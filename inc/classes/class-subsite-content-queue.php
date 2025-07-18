<?php
/**
 * Subsite Content Queue implementation.
 */
namespace Centralized_Content_Management\Inc;

use Centralized_Content_Management\Inc\Traits\Singleton;

/**
 * Subsite Content Queue class.
 */
class Subsite_Content_Queue {

	use Singleton;

	private $wpdb;

	private $subsite_table_name;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		// Check if the current site is not the central site.
		if ( ! Utils::is_central_site() ) {
			global $wpdb;
			$this->wpdb               = $wpdb;
			$current_site_id          = get_current_blog_id();
			$site_prefix              = $this->wpdb->get_blog_prefix( $current_site_id );
			$this->subsite_table_name = $site_prefix . 'ccm_subsite_queue';

			$this->setup_hooks();
		}
	}

	/**
	 * Setup hooks for the plugin.
	 *
	 * Registers admin menu items and AJAX callbacks for approving, rejecting,
	 * and previewing content requests. Also removes unnecessary submenu items.
	 *
	 * @return void
	 */
	public function setup_hooks() {
		add_action( 'admin_menu', array( $this, 'centralized_content_management_content_queue_admin_menu' ) );
		add_action( 'wp_ajax_centralized_content_management_content_queue_approve_request', array( $this, 'md_centralized_content_management_content_queue_approve_request_callback' ) );
		add_action( 'wp_ajax_centralized_content_management_reject_request', array( $this, 'md_centralized_content_management_reject_request_callback' ) );
		add_action( 'wp_ajax_centralized_content_management_cental_subsite_preview', array( $this, 'md_centralized_content_management_cental_subsite_preview_callback' ) );
		add_action(
			'admin_menu',
			function() {
				remove_submenu_page( 'md-ccm', 'md-ccm' );
			},
			999
		);
	}

	/**
	 * Adds menu and submenu pages for content queue management.
	 *
	 * This method checks if the current site is included in the synchronized subsites
	 * and if post approval is enabled. If conditions are met, it registers the main
	 * plugin menu and the "Content Queue" submenu for managing content approval.
	 *
	 * @return void
	 */
	public function centralized_content_management_content_queue_admin_menu() {
		// Retrive network and central settings.
		$centralized_content_manager_network_settings     = Utils::get_network_settings();
		$centralized_content_management_settings     = get_option( 'central_setting_data' );
		$sync_subsites            = isset( $centralized_content_manager_network_settings['sync_subsites'] ) ? $centralized_content_manager_network_settings['sync_subsites'] : array();
		$current_site_id          = get_current_blog_id();
		$is_post_approval_enabled = isset( $centralized_content_management_settings['post_approval'] ) ? $centralized_content_management_settings['post_approval'] : 0;

		// Check if current site is exists in sync subsites setting and check if post apporval setting is enabled or not.&& $is_post_approval_enabled
		if ( in_array( $current_site_id, $sync_subsites, true ) && $current_site_id !== Utils::get_central_site_id() ) {
			add_menu_page(
				__( 'Centralized Content Management', 'centralized-content-management' ),
				__( 'Centralized Content Management', 'centralized-content-management' ),
				'manage_options',
				'md-ccm',
				'__return_null',
				CENTRALIZED_CONTENT_MANAGEMENT_LOGO_ICON,
				2
			);

			add_submenu_page(
				'md-ccm',
				'Content Queue',
				'Content Queue',
				'manage_options',
				'content-queue',
				array( $this, 'centralized_content_management_central_to_masthead_content' ),
				2
			);
		}
	}

	/**
	 * Renders the content for the "Content Queue" submenu page.
	 *
	 * Includes the necessary file for displaying the central-to-subsite content queue.
	 *
	 * @return void
	 */
	public function centralized_content_management_central_to_masthead_content() {
		require_once CENTRALIZED_CONTENT_MANAGEMENT_DIR . 'inc/admin/ccm-central-to-subsite.php';
	}

	/**
	 * Handles AJAX request to approve a content queue item.
	 *
	 * This method validates the AJAX nonce and processes the approval of a queued
	 * content item. It retrieves the necessary data from the database, checks the
	 * sync type, and performs the appropriate action (update or delete).
	 * If successful, it updates the status in the custom table and sends the response.
	 *
	 * @return void Sends a JSON response with the result of the operation.
	 */
	public function md_centralized_content_management_content_queue_approve_request_callback() {
		$response   = array();
		$ajax_nonce = filter_input( INPUT_GET, 'ajax_nonce', FILTER_SANITIZE_SPECIAL_CHARS );
		$ajax_nonce = ( isset( $ajax_nonce ) ) ? $ajax_nonce : '';

		// Check nonce first
		if ( empty( $ajax_nonce ) || false === wp_verify_nonce( $ajax_nonce, 'restrict_post_modification_nonce' ) ) {
			$response['err']     = 1;
			$response['message'] = 'Unauthorized request. Please reload the page and try again.';

			wp_send_json( $response, 200 );
		}

		$primary_id = filter_input( INPUT_GET, 'primaryId', FILTER_SANITIZE_NUMBER_INT );
		$primary_id = ( isset( $primary_id ) ) ? $primary_id : '';

		if ( empty( $primary_id ) ) {
			$response['err']     = 1;
			$response['message'] = 'Something went wrong! Please reload the page and try again.';

			wp_send_json( $response, 200 );
		}

		$central_queue_table = Content_Queue_Helper::centralized_content_management_get_central_queue_table();

		// Get all data for this id.
		$result = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT sq.*, cq.* FROM {$this->subsite_table_name} sq LEFT JOIN {$central_queue_table} cq ON sq.central_id = cq.id WHERE sq.id = %d", $primary_id ), ARRAY_A ); // phpcs:ignore

		if ( empty( $result ) ) {
			$response['err']     = 1;
			$response['message'] = 'Post not found! Please reload the page and try again.';

			wp_send_json( $response, 200 );
		}

		$subsite_post_id     = ( isset( $result['local_post_id'] ) ) ? (int) $result['local_post_id'] : 0;
		$central_post_id     = ( isset( $result['central_post_id'] ) ) ? (int) $result['central_post_id'] : 0;
		$central_post_object = ( isset( $result['post_object'] ) ) ? $result['post_object'] : '';
		$post_type           = ( isset( $result['post_type'] ) ) ? html_entity_decode( $result['post_type'] ) : '';
		$post_action         = ( isset( $result['sync_status'] ) ) ? $result['sync_status'] : '';
		$sync_type           = ( isset( $result['sync_type'] ) ) ? $result['sync_type'] : '';
		$post_title          = ( isset( $central_post_obj_arr['title'] ) ) ? $central_post_obj_arr['title'] : '';

		if ( 'delete' === $sync_type ) {
			// Remove this post from masthead
			$sync_response = $this->centralized_content_management_remove_subsite_post( $primary_id, $subsite_post_id, $central_post_id, $post_title );
		} else {
			if ( empty( $central_post_object ) ) {
				$response['err']     = 1;
				$response['message'] = 'No data found for this record! Please reload the page and try again.';

				wp_send_json( $response, 200 );
			}

			$central_post_obj_arr = json_decode( $central_post_object, true );

			if ( empty( $central_post_obj_arr ) ) {
				$response['err']     = 1;
				$response['message'] = 'No data found for this record!';

				wp_send_json( $response, 200 );
			}

			$sync_response = $this->centralized_content_management_update_subsite_post( $subsite_post_id, $central_post_id, $post_type, $central_post_obj_arr );

			if ( isset( $sync_response['subsite_post_id'] ) && ! empty( $sync_response['subsite_post_id'] ) ) {
				$subsite_post_id     = $sync_response['subsite_post_id'];
				$action_performed_by = get_current_user_id();

				// Update status to custom masthead table.
				$this->wpdb->query(
					$this->wpdb->prepare( // phpcs:ignore
						"UPDATE {$this->subsite_table_name} SET `local_post_id` = %d, `sync_status` = 'synced', `modified_time` = %s, `approved_by` = %d WHERE `id` = %d", // phpcs:ignore
						$subsite_post_id, // phpcs:ignore
						current_time( 'mysql' ), // phpcs:ignore
						$action_performed_by, // phpcs:ignore
						$primary_id // phpcs:ignore
					)
				);
			}
		}

		wp_send_json( $sync_response, 200 );
	}

	/**
	 * Updates or creates a post on a subsite based on the central site's post data.
	 *
	 * @param int   $subsite_post_id       ID of the post on the subsite (0 for new posts).
	 * @param int   $central_post_id       ID of the central site's post.
	 * @param string $post_type            Post type to sync.
	 * @param array $central_post_obj_arr  Data of the central post (title, content, meta, etc.).
	 *
	 * @return array Response indicating success or failure of the operation.
	 */
	public function centralized_content_management_update_subsite_post( $subsite_post_id, $central_post_id, $post_type, $central_post_obj_arr ) {
		$response            = array();
		$subsite_option_data = get_option( 'central_setting_data' );
		$central_site_id     = Utils::get_central_site_id();
		$source_url          = get_site_url( $central_site_id );
		$sync_message        = '';
		$current_site_id     = get_current_blog_id();

		$post_args = array(
			'post_title'            => ( isset( $central_post_obj_arr['title'] ) ) ? $central_post_obj_arr['title'] : '',
			'post_type'             => $post_type,
			'post_name'             => ( isset( $central_post_obj_arr['post_name'] ) ) ? $central_post_obj_arr['post_name'] : '',
			'post_status'           => ( isset( $central_post_obj_arr['post_status'] ) ) ? $central_post_obj_arr['post_status'] : 'draft',
			'post_content'          => ( isset( $central_post_obj_arr['content'] ) ) ? $central_post_obj_arr['content'] : '',
			'post_content_filtered' => ( isset( $central_post_obj_arr['content_filtered'] ) ) ? $central_post_obj_arr['content_filtered'] : '',
		);

		// Retrive sync type.
		$sync_proccess_type = isset( $central_post_obj_arr['sync_proccess_type'] ) ? $central_post_obj_arr['sync_proccess_type'] : 'single';

		// Sync author if provided.
		if ( isset( $subsite_option_data['sync_users'] ) && 1 === (int) $subsite_option_data['sync_users'] ) {
			$central_post_author = ( isset( $central_post_obj_arr['post_author'] ) ) ? $central_post_obj_arr['post_author'] : get_current_user_id();
			$central_user        = new \WP_User( (int) $central_post_author, '', $central_site_id );

			if ( ! empty( $central_user ) ) {
				$user_role  = isset( $central_user->roles[0] ) ? $central_user->roles[0] : 'subscriber';
				$added_user = add_user_to_blog( $current_site_id, $central_user->ID, $user_role );

				if ( is_wp_error( $added_user ) ) {
					$post_args['post_author'] = get_current_user_id();
				} else {
					$post_args['post_author'] = $central_user->ID;
				}
			}
		}

		remove_post_type_support( $post_type, 'revisions' );

		if ( ( 0 === (int) $subsite_post_id ) ) {
			$subsite_post_id = wp_insert_post( $post_args, true );
			if ( empty( $subsite_post_id ) || is_wp_error( $subsite_post_id ) ) {
				$response['err']     = 1;
				$response['message'] = 'Error while creating post on the subsite. Please try again.';

				return $response;
			}
			$sync_message = 'Post successfully created on the subsite.';
		} else {
			// First check if this post exists in masthead site
			$local_post = get_post( $subsite_post_id );

			if ( ! empty( $local_post ) ) {
				$post_args['ID'] = $subsite_post_id;
				$subsite_post_id = wp_update_post( $post_args, true );
				$sync_message    = 'Post successfully updated on the subsite.';
			} else {
				$subsite_post_id = wp_insert_post( $post_args, true );
				$sync_message    = 'Post successfully created on the subsite.';
			}

			if ( empty( $subsite_post_id ) || is_wp_error( $subsite_post_id ) ) {
				$response['err']     = 1;
				$response['message'] = 'Error while updating post on the subsite. Please try again.';

				return $response;
			}
		}

		// Save central post id in subsite's post meta.
		update_post_meta( $subsite_post_id, '_central_post_id', $central_post_id );

		// Featured Image.
		if ( isset( $subsite_option_data['sync_media'] ) && 1 === (int) $subsite_option_data['sync_media'] ) {
			if ( isset( $central_post_obj_arr['featured_image']['id'] ) && isset( $central_post_obj_arr['featured_image']['url'] )
			&& ! empty( $central_post_obj_arr['featured_image']['id'] ) && ! empty( $central_post_obj_arr['featured_image']['url'] ) ) {
				$central_thumbnail_id = $central_post_obj_arr['featured_image']['id'];

				// Check if image exists based on central attachment ID.
				$existing_image = Sync_Process::centralized_content_management_find_attachment_by_central( $central_thumbnail_id );

				if ( false !== $existing_image ) {
					// Set post thumbnail in subsite post.
					$thumbnail_id = $existing_image['id'];
					Sync_Process::centralized_content_management_set_post_thumbnail( $subsite_post_id, $thumbnail_id );
				} else {
					// Create new attachment in subsite.
					$added_image = Sync_Process::centralized_content_management_add_attachment_subsite( $central_site_id, $central_thumbnail_id, $central_post_obj_arr['featured_image']['filepath'], $subsite_post_id, $central_post_obj_arr['featured_image']['image_author'] );
					if ( isset( $added_image['id'] ) && ! empty( $added_image['id'] ) ) {
						// Set post thumbnail in subsite post.
						$thumbnail_id = $added_image['id'];
						Sync_Process::centralized_content_management_set_post_thumbnail( $subsite_post_id, $thumbnail_id );
					}
				}
			}
		}

		// Taxonomy.
		if ( isset( $subsite_option_data['taxonomies'] ) && ! empty( $subsite_option_data['taxonomies'] ) ) {
			if ( isset( $central_post_obj_arr['taxonomy'] ) && ! empty( $central_post_obj_arr['taxonomy'] ) && ! empty( $subsite_post_id ) ) {
				foreach ( $central_post_obj_arr['taxonomy'] as $tax => $tax_terms ) {
					if ( taxonomy_exists( $tax ) && in_array( $tax, $subsite_option_data['taxonomies'], true ) ) {
						// Set post terms.
						Sync_Process::centralized_content_management_update_subsite_terms( $tax_terms, $subsite_post_id, $tax );
					}
				}
			}
		}

		// Check if post meta is allowed to sync.
		if ( isset( $subsite_option_data['sync_post_meta'] ) && 1 === (int) $subsite_option_data['sync_post_meta'] ) {
			// Meta.
			if ( isset( $central_post_obj_arr['meta_fields'] ) && ! empty( $central_post_obj_arr['meta_fields'] ) ) {

				// Remove all existing meta first.
				$new_target_post_meta = get_post_meta( $subsite_post_id );

				if ( ! empty( $new_target_post_meta ) && is_array( $new_target_post_meta ) ) {
					$new_target_post_meta_keys = array_keys( $new_target_post_meta );
					foreach ( $new_target_post_meta_keys as $new_target_post_meta_key ) {
						delete_post_meta( $new_target_post_meta, $new_target_post_meta_key );
					}
				}

				// Update with new values.
				foreach ( $central_post_obj_arr['meta_fields'] as $meta_key => $meta_value ) {
					$meta_value = maybe_unserialize( $meta_value );
					update_post_meta( $subsite_post_id, $meta_key, $meta_value );
				}
			}

			// Check for Yoast Primary Category.
			if ( isset( $central_post_obj_arr['meta_fields']['_yoast_wpseo_primary_category_slug'] ) && ! empty( $central_post_obj_arr['meta_fields']['_yoast_wpseo_primary_category_slug'] ) ) {
				// check if the term exists
				$target_term = term_exists( $central_post_obj_arr['meta_fields']['_yoast_wpseo_primary_category_slug'], 'category' ); // phpcs:ignore
				if ( $target_term ) {
					$target_term_id = (int) $target_term['term_id'];
					update_post_meta( $subsite_post_id, '_yoast_wpseo_primary_category', $target_term_id );
					delete_post_meta( $subsite_post_id, '_yoast_wpseo_primary_category_slug' );
				}
			}

			// acf_fields.
			if ( isset( $central_post_obj_arr['acf_fields'] ) && ! empty( $central_post_obj_arr['acf_fields'] ) ) {
				foreach ( $central_post_obj_arr['acf_fields'] as $key => $field ) {
					$acf_meta_data = '';
					if ( isset( $field['type'], $field['value'] ) ) {
						$acf_field_type = $field['type'];
						$acf_field_val  = $field['value'];

						switch ( $acf_field_type ) {
							case 'taxonomy':
								$acf_meta_data = $this->centralized_content_management_sync_save_rel_tax_data( $acf_field_val );
								break;
							case 'user':
								$acf_meta_data = $this->centralized_content_management_sync_save_rel_user_data( $acf_field_val );
								break;
							case 'link':
								$acf_meta_data = $this->centralized_content_management_sync_save_link_rel_data( $acf_field_val, $source_url );
								break;
							case 'post_object':
							case 'relationship':
							case 'page_link':
							case 'file':
							case 'image':
							case 'gallery':
								$acf_meta_data = $this->centralized_content_management_sync_save_post_rel_data( $acf_field_val );
								break;
						}
					}
					if ( function_exists( 'update_field' ) ) {
						update_field( $key, $acf_meta_data, $subsite_post_id );
					} else {
						update_post_meta( $subsite_post_id, $key, $acf_meta_data );
					}
				}
			}
		}

		// Check if sync media is allowed to sync.
		if ( isset( $subsite_option_data['sync_media'] ) && 1 === (int) $subsite_option_data['sync_media'] ) {
			// Content images.
			$content_media = isset( $central_post_obj_arr['content_media'] ) ? $central_post_obj_arr['content_media'] : array();

			// Set cron schedule for images.
			if ( ! empty( $content_media ) && ! wp_next_scheduled( 'centralized_content_management_subsite_sync_images_cron', array( $content_media, $subsite_post_id ) ) ) {
				wp_schedule_single_event( time(), 'centralized_content_management_subsite_sync_images_cron', array( $content_media, $subsite_post_id ) );
			}
		}

		// Log the action.
		$log_data = array(
			'post_id'    => $central_post_id,
			'post_name'  => $post_args['post_title'],
			'sync_sites' => array(
				array(
					'site_id'     => $current_site_id,
					'sync_time'   => current_time( 'mysql' ),
					'sync_status' => 'bulk' === $sync_proccess_type ? 'Bulk Synced' : 'Synced',
					'sync_note'   => $sync_message,
				),
			),
		);
		Utils::insert_log( $log_data );

		$subsite_synced_data = array(
			'success'         => 1,
			'message'         => $sync_message,
			'sync_status'     => 'bulk' === $sync_proccess_type ? 'Bulk Synced' : 'Synced',
			'subsite_post_id' => $subsite_post_id,
			'current_site_id' => $current_site_id,
			'log_data'        => array(
				'post_id'     => $subsite_post_id,
				'post_name'   => $post_args['post_title'],
				'site_id'     => $current_site_id,
				'site_name'   => Utils::get_blogname( $current_site_id ),
				'sync_time'   => current_time( 'mysql' ),
				'sync_status' => 'bulk' === $sync_proccess_type ? 'Bulk Synced' : 'Synced',
				'sync_note'   => $sync_message,
			),
		);

		Content_Queue_Helper::centralized_content_management_central_update_synced_data( $central_post_id, $subsite_synced_data );

		$response['err']             = 0;
		$response['message']         = $sync_message;
		$response['subsite_post_id'] = $subsite_post_id;

		return $response;
	}

	/**
	 * Handles rejection of a synchronization request from a subsite.
	 *
	 * @return void Outputs a JSON response with the result of the operation.
	 */
	public function md_centralized_content_management_reject_request_callback() {
		$response   = array();
		$ajax_nonce = filter_input( INPUT_POST, 'ajax_nonce', FILTER_SANITIZE_SPECIAL_CHARS );
		$ajax_nonce = ( isset( $ajax_nonce ) ) ? $ajax_nonce : '';

		// Check nonce first.
		if ( empty( $ajax_nonce ) || false === wp_verify_nonce( $ajax_nonce, 'restrict_post_modification_nonce' ) ) {
			$response['err']     = 1;
			$response['message'] = 'Unauthorized request. Please reload the page and try again.';

			wp_send_json( $response, 200 );
		}

		$primary_id      = filter_input( INPUT_POST, 'primaryId', FILTER_SANITIZE_NUMBER_INT ) ?: '';
		$reject_msg      = filter_input( INPUT_POST, 'rejectMsg', FILTER_SANITIZE_SPECIAL_CHARS ) ?: '';
		$central_post_id = filter_input( INPUT_POST, 'centralPostId', FILTER_SANITIZE_NUMBER_INT ) ?: '';
		$post_title      = filter_input( INPUT_POST, 'postTitle', FILTER_SANITIZE_SPECIAL_CHARS ) ?: '';
		$user            = filter_input( INPUT_POST, 'postUser', FILTER_SANITIZE_SPECIAL_CHARS ) ?: '';


		if ( empty( $primary_id ) || empty( $reject_msg ) ) {
			$response['err']     = 1;
			$response['message'] = 'Something went wrong! Please reload the page and try again.';

			wp_send_json( $response, 200 );
		}

		$action_performed_by = get_current_user_id();
		$user_data           = get_userdata( $action_performed_by );
		$user_full_name      = '';
		if ( ! empty( $user_data ) ) {
			$user_full_name = trim( $user_data->first_name . ' ' . $user_data->last_name );
			$user_full_name = ( empty( $user_full_name ) ) ? $user_data->display_name : $user_full_name;
		}

		// Update in database
		$reject_request = $this->wpdb->query(
			$this->wpdb->prepare( // phpcs:ignore
				// phpcs:ignore
				"UPDATE {$this->subsite_table_name} SET `sync_status` = 'rejected', `approved_by` = %d, `reject_comment` = %s, `modified_time` = %s
				WHERE `id` = %d", // phpcs:ignore
				$action_performed_by, // phpcs:ignore
				$reject_msg, // phpcs:ignore
				current_time( 'mysql' ), // phpcs:ignore
				$primary_id // phpcs:ignore
			)
		);

		if ( false !== $reject_request ) {

			// Log the action
			$log_data = array(
				'post_id'    => $central_post_id,
				'post_name'  => $post_title,
				'sync_sites' => array(
					array(
						'site_id'     => get_current_blog_id(),
						'sync_time'   => current_time( 'mysql' ),
						'sync_status' => 'Rejected',
						'sync_note'   => 'Changes to this post were rejected at subsite by ' . $user_full_name . ' with message: ' . $reject_msg,
					),
				),
			);
			Utils::insert_log( $log_data );

			// Send email
			$reject_email_recipients = array();
			$masthead_admiin_email   = get_option( 'admin_email' );
			$ccm_email_recipients    = get_option( 'ccm_notify_email' );
			$ccm_email_recipients    = ( empty( $dsf_email_recipients ) ) ? array() : explode( ',', $ccm_email_recipients );
			$current_site            = get_blog_details()->blogname;
			$subject                 = $current_site . ' - CCM - Change Rejected';

			// Get central site admin email
			$central_site_id     = Utils::get_central_site_id();
			$central_admin_email = Utils::centralized_content_management_get_admin_email( $central_site_id );
			$post_edit_url       = get_admin_url( $central_site_id, 'post.php?post=' . $central_post_id . '&action=edit', 'admin' );

			// Email of rejected user
			$rejected_user = get_userdata( $user );
			if ( ! empty( $rejected_user ) ) {
				array_push( $reject_email_recipients, $rejected_user->user_email );
			}

			array_push( $reject_email_recipients, $masthead_admiin_email, $central_admin_email );

			$reject_email_recipients = array_unique( array_merge( $reject_email_recipients, $ccm_email_recipients ) );

			$body  = '<p>Hello,</p>';
			$body .= '<p>Changes for <a href="' . esc_url( $post_edit_url ) . '">' . $post_title . '</a> have been rejected for ' . $current_site . ' masthead by ' . $user_full_name . ' with below message:</p>';
			$body .= '<p>' . $reject_msg . '</p><br>';
			$body .= 'Thank You.';

			Utils::centralized_content_management_send_email( $reject_email_recipients, $subject, $body );

			$response['err']     = 0;
			$response['message'] = 'Request has been rejected successfully.';

		} else {
			$response['err']     = 1;
			$response['message'] = 'There was an error while processing your request.';
		}

		wp_send_json( $response, 200 );
	}

	/**
	 * Removes a post from a subsite by moving it to the trash and updating the synchronization status.
	 *
	 *
	 * @param int    $id             The ID of the synchronization record.
	 * @param int    $subsite_post_id The ID of the post on the subsite.
	 * @param int    $central_post_id The ID of the corresponding post on the central site.
	 * @param string $post_title      The title of the post being removed.
	 *
	 * @return array JSON-compatible response with error status and a success/error message.
	 */
	public function centralized_content_management_remove_subsite_post( $id, $subsite_post_id, $central_post_id, $post_title ) {
		$response = array();

		if ( ! isset( $subsite_post_id ) || empty( $subsite_post_id ) ) {
			$response['err']     = 1;
			$response['message'] = 'Post not found! Please reload the page and try again.';

			return $response;
		}

		// Check if delete action is allowed.
		$subsite_option_data = get_option( 'central_setting_data', array() );
		$delete_on_subsite   = isset( $subsite_option_data['delete_on_subsite'] ) ? (int) $subsite_option_data['delete_on_subsite'] : 0;

		if ( 0 === $delete_on_subsite ) {
			$response['err']     = 1;
			$response['message'] = 'Delete action is not allowed on this subsite.';

			return $response;
		}

		$subsite_post_status = get_post_status( $subsite_post_id );
		if ( false !== $subsite_post_status && 'trash' === $subsite_post_status ) {
			$subsite_delete_msg = 'This post is already in Trash.';
		} else {
			$trash_action_response = wp_trash_post( $subsite_post_id );

			if ( false === $trash_action_response ) {
				$response['err']     = 1;
				$response['message'] = 'There was an error while deleting the post. Please try again.';

				return $response;
			}

			$subsite_delete_msg = 'Post successfully removed from subsite.';
		}

		// Update db record.
		$subsite_post_removal = $this->wpdb->query(
			$this->wpdb->prepare( // phpcs:ignore
				"UPDATE {$this->subsite_table_name} SET `sync_status` = 'deleted' WHERE `id` = %d", // phpcs:ignore
				$id // phpcs:ignore
			)
		);

		// Log the action
		$log_data = array(
			'post_id'    => $central_post_id,
			'post_name'  => $post_title,
			'sync_sites' => array(
				array(
					'site_id'     => get_current_blog_id(),
					'sync_time'   => current_time( 'mysql' ),
					'sync_status' => 'Synced',
					'sync_note'   => $subsite_delete_msg,
				),
			),
		);
		Utils::insert_log( $log_data );

		// @todo Update in central table as well.

		if ( ! empty( $subsite_post_removal ) ) {
			$response['err']     = 0;
			$response['message'] = $subsite_delete_msg;
		} else {
			$response['err']     = 1;
			$response['message'] = 'There was an error while processing your request.';
		}

		return $response;
	}

	/**
	 * Saves ACF taxonomy relationship data.
	 *
	 * @param array $acf_field_val The ACF field value containing taxonomy data (e.g., slug and taxonomy).
	 *
	 * @return int|array|string Returns the term ID(s) if the term exists, or an empty string if the input data is invalid.
	 */
	private function centralized_content_management_sync_save_rel_tax_data( $acf_field_val ) {
		if ( empty( $acf_field_val ) ) {
			return '';
		}

		$meta_data = array();
		foreach ( $acf_field_val as $val ) {
			// check if the term exists
			$target_term = term_exists( $val['slug'], $val['taxonomy'] ); // phpcs:ignore
			if ( $target_term ) {
				if ( count( $acf_field_val ) > 1 ) {
					$meta_data[] = (int) $target_term['term_id'];
				} else {
					$meta_data = (int) $target_term['term_id'];
				}
			}
		}

		return $meta_data;
	}

	/**
	 * Saves ACF user relationship data.
	 *
	 *
	 * @param array $acf_field_val The ACF field value containing user data (e.g., user_login and user_email).
	 *
	 * @return int|array|string Returns the user ID(s) if the user exists, or an empty string if the input data is invalid.
	 */
	private function centralized_content_management_sync_save_rel_user_data( $acf_field_val ) {
		if ( empty( $acf_field_val ) ) {
			return '';
		}

		$meta_data = array();
		foreach ( $acf_field_val as $val ) {
			$user_id = 0;

			// check if the user exists
			$username_exists = ( isset( $val['user_login'] ) ) ? username_exists( $val['user_login'] ) : false;
			$email_exists    = ( isset( $val['user_email'] ) ) ? email_exists( $val['user_email'] ) : false;

			if ( ! empty( $username_exists ) ) {
				$user_id = $username_exists;
			} elseif ( ! empty( $email_exists ) ) {
				$user_id = $email_exists;
			}
			if ( ! empty( $user_id ) ) {
				if ( count( $acf_field_val ) > 1 ) {
					$meta_data[] = (int) $user_id;
				} else {
					$meta_data = (int) $user_id;
				}
			}
		}

		return $meta_data;
	}

	/**
	 * Saves ACF link relationship data.
	 *
	 * This function processes link relationship data from an ACF field, modifying the URL
	 * based on a source URL and saving the relevant link data (URL, title, and target).
	 *
	 * @param array  $acf_field_val The ACF field value containing link data (e.g., 'url', 'title', 'target').
	 * @param string $source_url    The source URL used to modify the host of the link URL.
	 *
	 * @return array|string Returns the modified link data (url, title, and target), or an empty string if the input data is invalid.
	 */
	private function centralized_content_management_sync_save_link_rel_data( $acf_field_val, $source_url ) {
		if ( empty( $acf_field_val ) ) {
			return '';
		}

		$meta_data       = array();
		$link_url        = $acf_field_val['url'];
		$target_site_url = $this->centralized_content_management_sync_change_img_host( $link_url, $source_url );
		$meta_data       = array(
			'url'    => $target_site_url,
			'title'  => $acf_field_val['title'],
			'target' => $acf_field_val['target'],
		);

		return $meta_data;
	}

	/**
	 * Syncs the image URL by replacing the source URL with the target site URL.
	 *
	 * @param string $img_url    The original image URL to be updated.
	 * @param string $source_url The source URL to be replaced with the current site's URL.
	 *
	 * @return string The updated image URL with the source URL replaced by the target site's URL.
	 */
	private function centralized_content_management_sync_change_img_host( $img_url, $source_url ) {
		$target_site_url = site_url();

		return str_replace( $source_url, $target_site_url, $img_url );
	}

	/**
	 * Saves related post data from an ACF field to the target site.
	 *
	 * @param array $acf_field_val The value of the ACF field containing post data.
	 *
	 * @return mixed Returns an array of post IDs if multiple posts are found,
	 *               or a single post ID if only one is found.
	 */
	private function centralized_content_management_sync_save_post_rel_data( $acf_field_val ) {
		if ( empty( $acf_field_val ) ) {
			return '';
		}

		$meta_data = array();
		foreach ( $acf_field_val as $val ) {
			$target_post_id   = 0;
			$source_post_type = $val['post_type'];

			if ( 'attachment' === $source_post_type ) {

				// check if this image exists in target site
				$existing_img_args = array(
					'post_type'              => array( 'attachment' ),
					'post_status'            => 'inherit',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				);

				$existing_img_args['meta_query'] = array( // phpcs:ignore
					array(
						'key'   => 'mws_source_img_url',
						'value' => $val['url'],
					),
				);

				// The Query
				$existing_img_query = new \WP_Query( $existing_img_args );

				if ( isset( $existing_img_query->posts ) && ! empty( $existing_img_query->posts ) ) {
					$target_post_id = $existing_img_query->posts[0];
				}
			} else {
				// Check if post exists in the target site
				$args_posts = array(
					'post_type'              => $source_post_type,
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'fields'                 => 'ids',
				);

				$args_posts['meta_query'] = array( // phpcs:ignore
					array(
						'key'   => '_central_post_id',
						'value' => $val['ID'],
					),
				);

				$is_post_present = new \WP_Query( $args_posts );

				if ( isset( $is_post_present->posts ) && ! empty( $is_post_present->posts ) ) {
					$target_post_id = $is_post_present->posts[0];
				}
			}

			if ( ! empty( $target_post_id ) ) {
				if ( count( $acf_field_val ) > 1 ) {
					$meta_data[] = (int) $target_post_id;
				} else {
					$meta_data = (int) $target_post_id;
				}
			}
		}

		return $meta_data;
	}

	/**
	 * Callback function to preview changes between a central and subsite post.
	 *
	 * This function compares the content of a post from a central site with a post on a subsite.
	 * It checks if the post has been deleted, fetches the post data, and calculates the differences
	 * between the local and central post objects. The differences are returned as a response for preview.
	 *
	 * @return void Sends a JSON response with the differences or an error message.
	 */
	public function md_centralized_content_management_cental_subsite_preview_callback() {
		$response   = array();
		$diff       = '';
		$ajax_nonce = filter_input( INPUT_GET, 'ajax_nonce', FILTER_SANITIZE_SPECIAL_CHARS );
		$ajax_nonce = ( isset( $ajax_nonce ) ) ? $ajax_nonce : '';

		// Check nonce first
		if ( empty( $ajax_nonce ) || false === wp_verify_nonce( $ajax_nonce, 'restrict_post_modification_nonce' ) ) {
			$response['err']     = 1;
			$response['message'] = 'Unauthorized request. Please reload the page and try again.';

			wp_send_json( $response, 200 );
		}

		$primary_id         = filter_input( INPUT_GET, 'primaryId', FILTER_SANITIZE_NUMBER_INT );
		$primary_id         = (isset( $primary_id ) && $primary_id !== null) ? $primary_id : '';

		$local_post_id      = filter_input( INPUT_GET, 'localPostId', FILTER_SANITIZE_NUMBER_INT );
		$local_post_id      = (isset( $local_post_id ) && $local_post_id !== null) ? $local_post_id : '';

		$central_post_id    = filter_input( INPUT_GET, 'centralPostId', FILTER_SANITIZE_NUMBER_INT );
		$central_post_id    = (isset( $central_post_id ) && $central_post_id !== null) ? $central_post_id : '';

		$post_type          = filter_input( INPUT_GET, 'postType', FILTER_SANITIZE_SPECIAL_CHARS );
		$post_type          = (isset( $post_type ) && $post_type !== null) ? $post_type : '';

		$post_title         = filter_input( INPUT_GET, 'postTitle', FILTER_SANITIZE_SPECIAL_CHARS );
		$post_title         = (isset( $post_title ) && $post_title !== null) ? $post_title : '';

		$records            = array();
		$local_post_obj_arr = array();

		if ( empty( $primary_id ) ) {
			$response['err']     = 1;
			$response['message'] = 'Something went wrong! Please reload the page and try again.';

			wp_send_json( $response, 200 );
		}

		$central_site_table = Content_Queue_Helper::centralized_content_management_get_central_queue_table();

		// Get all data for this id
		$result = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT sq.*, cq.post_object_compare FROM {$this->subsite_table_name} sq LEFT JOIN {$central_site_table} cq ON sq.central_id = cq.id WHERE sq.id = %d", $primary_id ), ARRAY_A ); // phpcs:ignore

		if ( empty( $result ) ) {
			$response['err']     = 1;
			$response['message'] = 'Post not found! Please reload the page and try again.';

			wp_send_json( $response, 200 );
		}

		$central_post_object = ( isset( $result['post_object_compare'] ) ) ? $result['post_object_compare'] : '';
		$sync_type           = ( isset( $result['sync_type'] ) ) ? $result['sync_type'] : '';
		$post_type_obj       = get_post_type_object( $post_type );
		$post_type_label     = ( ! empty( $post_type_obj ) ) ? $post_type_obj->labels->singular_name : '';

		if ( 'delete' === $sync_type ) {
			$response['err']  = 1;
			$response['diff'] = 'You have deleted this ' . $post_type_label . '.';

			wp_send_json( $response, 200 );
		} else {
			if ( empty( $central_post_object ) ) {
				$response['err']     = 1;
				$response['message'] = 'No data found for this record! Please reload the page and try again.';

				wp_send_json( $response, 200 );
			}

			$central_post_obj_arr = json_decode( $central_post_object, true );
			if ( ! empty( $local_post_id ) ) {
				$local_post                    = get_post( $local_post_id );
				$masthead_prepared_post_object = Content_Queue_Helper::centralized_content_management_sync_prepare_post_object( $local_post_id, $local_post );
				$local_post_object             = $masthead_prepared_post_object['post_object_compare'];
				$local_post_obj_arr            = json_decode( $local_post_object, true );
			}

			if ( ! empty( $central_post_obj_arr ) ) {
				$central_site_id  = Utils::get_central_site_id();
				$current_blog_id  = get_current_blog_id();
				$central_site_url = get_site_url( $central_site_id );
				$local_site_url   = site_url();

				foreach ( $central_post_obj_arr as $key => $value ) {
					$diff_title = isset( $value['label'] ) ? $value['label'] : $key;
					$new        = $value['value'] ?? '';

					$old = isset( $local_post_obj_arr[ $key ]['value'] ) ? $local_post_obj_arr[ $key ]['value'] : '';
					if ( is_main_site( $central_site_id ) ) {
						$old = str_replace( '/uploads/sites/' . $current_blog_id . '/', '/uploads/', $old );
					} else {
						$old = str_replace( '/uploads/sites/' . $current_blog_id . '/', '/uploads/sites/' . $central_site_id . '/', $old );
					}
					$old       = str_replace( $local_site_url, $central_site_url, $old );
					$main_diff = wp_text_diff( $old, $new );
					if ( ! empty( $main_diff ) ) {
						$diff .= '<h3>' . $diff_title . '</h3>';
						$diff .= $main_diff;
					}
				}
			}
		}

		$response['err']  = 0;
		$response['diff'] = $diff;

		wp_send_json( $response, 200 );
	}
}

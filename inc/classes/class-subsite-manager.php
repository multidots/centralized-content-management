<?php
/**
 * Subsite Manager for the MD Centralized Content Management plugin.
 *
 * This class is responsible for managing content modification restrictions
 * and synchronization settings for individual subsites within the WordPress
 * multisite network. It includes AJAX handling for post modification
 * restrictions, as well as filters to control and customize post and page
 * row actions and bulk actions available to subsite users.
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
class Subsite_Manager {

	use Singleton;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->setup_central_admin_hooks();
	}

	/**
	 * Function is used to define admin hooks.
	 *
	 * @since   1.0.0
	 */
	public function setup_central_admin_hooks() {
		add_action( 'wp_ajax_restrict_post_modification', array( $this, 'centralized_content_management_restrict_post_modification_ajax_callback' ) );
		add_filter( 'post_row_actions', array( $this, 'centralized_content_management_remove_row_actions_post' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'centralized_content_management_remove_row_actions_post' ), 10, 2 );
	}

	/**
	 * Function to handle subsite restrict post modification using AJAX.
	 */
	public function centralized_content_management_restrict_post_modification_ajax_callback() {
		// Declare empty array to prepare response.
		$response = array();

		// Retrieve the central site settings.
		// $subsite_option_data = get_option( 'subsite_option_data' );
		$subsite_option_data = get_option( 'central_setting_data' );

		// Check if `Allow Modification` setting is enable or not.
		if ( isset( $subsite_option_data['allow_modification'] ) && $subsite_option_data['allow_modification'] ) {
			$response['success'] = false;
			$response['message'] = __( 'This post does not need to be restricted.', 'centralized-content-management' );

			wp_send_json_error( $response );
		}

		// Check the nonce.
		$ajax_nonce = filter_input( INPUT_POST, 'ajax_nonce', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( ! $ajax_nonce || ! wp_verify_nonce( $ajax_nonce, 'restrict_post_modification_nonce' ) ) {
			$response['success'] = false;
			$response['message'] = __( 'Nonce verification failed. Please refresh the page and try again.', 'centralized-content-management' );

			wp_send_json_error( $response );
		}

		// Sanitize inputs.
		$current_post_id   = filter_input( INPUT_POST, 'current_post_id', FILTER_SANITIZE_NUMBER_INT ) ?: 0;
		$current_post_type = filter_input( INPUT_POST, 'current_post_type', FILTER_SANITIZE_SPECIAL_CHARS ) ?: '';

		// Get central_post_id by current subsite post id.
		$central_post_id = get_post_meta( $current_post_id, '_central_post_id', true );

		// Check if the post type is managed through the central settings.
		if ( isset( $subsite_option_data['post_types'] ) && in_array( $current_post_type, $subsite_option_data['post_types'] ) ) {
			$response['post_modification'] = false;

			// Check if the post is synced via the central site.
			if ( $central_post_id ) {
				$response['success']           = true;
				$response['post_modification'] = true;
				$response['message']           = __( 'This post is managed by the central site. Editing is restricted.', 'centralized-content-management' );
			} else {
				$response['success']           = false;
				$response['post_modification'] = false;
				$response['message']           = __( 'This post is not synced with the central site.', 'centralized-content-management' );
			}
		} else {
			$response['success']           = false;
			$response['post_modification'] = false;
			$response['message']           = __( 'This post type is not managed by the central site.', 'centralized-content-management' );
		}

		// Return success response.
		wp_send_json_success( $response );
	}

	// Remove Raw Action.
	public function centralized_content_management_remove_row_actions_post( $actions, $post ) {
		// Retrieve the subsite option data.
		$subsite_option_data = get_option( 'central_setting_data' );

		if ( isset( $subsite_option_data['allow_modification'] ) && ! $subsite_option_data['allow_modification'] ) {
			// Retrive current post's post_type.
			$current_post_type = $post->post_type;

			// Get central_post_id by current subsite post id.
			$central_post_id = get_post_meta( $post->ID, '_central_post_id', true );

			if ( ( isset( $subsite_option_data['post_types'] ) && in_array( $current_post_type, $subsite_option_data['post_types'] ) ) && $central_post_id ) {
				unset( $actions['inline hide-if-no-js'] );
				unset( $actions['trash'] );
				unset( $actions['edit'] );
			}
		}

		return $actions;
	}
}

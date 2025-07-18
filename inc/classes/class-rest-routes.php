<?php
/**
 * REST API routes functionality for the MD Centralized Content Management plugin.
 *
 * This class registers and manages all custom REST API endpoints for
 * the MD Centralized Content Management plugin. It provides the
 * functionality to interact with various resources related to content
 * synchronization across subsites, including endpoints for managing
 * post types, taxonomies, media, and user associations.
 *
 * @package    Centralized_Content_Management
 * @subpackage Centralized_Content_Management/admin
 * @author     Multidots <info@multidots.com>
 */

namespace Centralized_Content_Management\Inc;

use Centralized_Content_Management\Inc\Traits\Singleton;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use WP_Query;
use Exception;

use function PHPSTORM_META\type;

/**
 * Main class file.
 */
class Rest_Routes {

	use Singleton;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( ! Utils::is_central_site() ) {
			$this->setup_rest_routes_hooks();
		} else {
			$this->setup_central_rest_routes_hooks();
		}
	}

	/**
	 * Function is used to define rest routes hooks.
	 *
	 * @since   1.0.0
	 */
	public function setup_rest_routes_hooks() {
		add_action( 'rest_api_init', array( $this, 'centralized_content_management_register_custom_routes' ) );
	}

	/**
	 * Function is used to define central rest routes hooks.
	 *
	 * @since   1.0.0
	 */
	public function setup_central_rest_routes_hooks() {
		add_action( 'rest_api_init', array( $this, 'centralized_content_management_register_central_custom_routes' ) );
	}

	/**
	 * Register custom REST API routes.
	 */
	public function centralized_content_management_register_custom_routes() {
		// Register custom REST API route for sync posts to the subsites.
		register_rest_route(
			'md-ccm/v1',
			'/sync-post',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'centralized_content_management_sync_post_api_callback' ),
				'permission_callback' => array( $this, 'centralized_content_management_authorize_request' ),
			)
		);

		// Register custom REST API route for trash posts to the subsites.
		register_rest_route(
			'md-ccm/v1',
			'/trash-post',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'centralized_content_management_trash_post_api_callback' ),
				'permission_callback' => array( $this, 'centralized_content_management_authorize_request' ),
			)
		);

		// Register custom REST API route for untrash posts to the subsites.
		register_rest_route(
			'md-ccm/v1',
			'/untrash-post',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'centralized_content_management_untrash_post_api_callback' ),
				'permission_callback' => array( $this, 'centralized_content_management_authorize_request' ),
			)
		);

		// Register custom REST API route for delete posts to the subsites.
		register_rest_route(
			'md-ccm/v1',
			'/delete-post',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'centralized_content_management_delete_post_api_callback' ),
				'permission_callback' => array( $this, 'centralized_content_management_authorize_request' ),
			)
		);
	}

	public function centralized_content_management_register_central_custom_routes() {
		// Register custom REST API route for sync posts to the subsites.
		register_rest_route(
			'md-ccm/v1',
			'/update-synced-data',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'centralized_content_management_update_synced_data' ),
				'permission_callback' => array( $this, 'centralized_content_management_authorize_request' ),
			)
		);
	}

	/**
	 * Callback function to sync posts to the subsites.
	 *
	 * @param WP_REST_Request $request The REST API request.
	 * @return WP_REST_Response The REST API response.
	 */
	public function centralized_content_management_sync_post_api_callback( WP_REST_Request $request ) {
		// Define WP_IMPORTING.
		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		$params    = $request->get_params();
		$post_data = array(
			'post_title'        => ( isset( $params['post_title'] ) && ! empty( $params['post_title'] ) ) ? sanitize_text_field( $params['post_title'] ) : '',
			'post_content'      => ( isset( $params['post_content'] ) && ! empty( $params['post_content'] ) ) ? wp_kses_post( $params['post_content'] ) : '',
			'post_status'       => ( isset( $params['post_status'] ) && ! empty( $params['post_status'] ) ) ? sanitize_text_field( $params['post_status'] ) : 'publish',
			'post_type'         => ( isset( $params['post_type'] ) && ! empty( $params['post_type'] ) ) ? sanitize_text_field( $params['post_type'] ) : 'post',
			'post_name'         => ( isset( $params['post_name'] ) && ! empty( $params['post_name'] ) ) ? sanitize_text_field( $params['post_name'] ) : '',
			'post_date'         => ( isset( $params['post_date'] ) && ! empty( $params['post_date'] ) ) ? sanitize_text_field( $params['post_date'] ) : wp_date( 'Y-m-d H:i:s' ),
			'post_date_gmt'     => ( isset( $params['post_date_gmt'] ) && ! empty( $params['post_date_gmt'] ) ) ? sanitize_text_field( $params['post_date_gmt'] ) : wp_date( 'Y-m-d H:i:s' ),
			'post_modified'     => ( isset( $params['post_modified'] ) && ! empty( $params['post_modified'] ) ) ? sanitize_text_field( $params['post_modified'] ) : wp_date( 'Y-m-d H:i:s' ),
			'post_modified_gmt' => ( isset( $params['post_modified_gmt'] ) && ! empty( $params['post_modified_gmt'] ) ) ? sanitize_text_field( $params['post_modified_gmt'] ) : wp_date( 'Y-m-d H:i:s' ),
		);

		$disable_sync      = ( isset( $params['disable_sync'] ) && ! empty( $params['disable_sync'] ) ) ? sanitize_text_field( $params['disable_sync'] ) : 'false';
		$central_post_id   = ( isset( $params['central_post_id'] ) && ! empty( isset( $params['central_post_id'] ) ) ) ? (int) sanitize_text_field( $params['central_post_id'] ) : 0;
		$central_post_type = ( isset( $params['post_type'] ) && ! empty( $params['post_type'] ) ) ? sanitize_text_field( $params['post_type'] ) : 'post';
		$central_images    = ( isset( $params['central_images'] ) && ! empty( $params['central_images'] ) ) ? $params['central_images'] : array();
		$central_site_id   = Utils::centralized_content_management_get_central_site_id();
		$source_url        = ( isset( $params['source_url'] ) && ! empty( $params['source_url'] ) ) ? $params['source_url'] : '';
		$current_site_id   = get_current_blog_id();
		$sync_type         = isset( $params['sync_type'] ) ? sanitize_text_field( $params['sync_type'] ) : 'single';
		$sync_text         = ( 'single' === $sync_type ) ? __('Synced', 'centralized-content-management') : __('Bulk Synced', 'centralized-content-management');
		$response          = array();

		// Check if post exists in subsite or not.
		$subsite_post_id = Sync_Process::centralized_content_management_get_subsite_post( $central_post_type, $central_post_id );

		// Disable syncing post to subsite when disable sync option is true.
		if ( 'true' === $disable_sync ) {
			// Log the sync operation.
			$log_data = array(
				'post_id'     => $central_post_id,
				'post_name'   => $post_data['post_title'],
				'site_id'     => $current_site_id,
				'site_name'   => Utils::get_blogname( $current_site_id ),
				'sync_time'   => current_time( 'mysql' ),
				'sync_status' => __( 'Skipped', 'centralized-content-management' ),
				'sync_note'   => __( 'The sync setting for this post is disabled, so it will not be synchronized.', 'centralized-content-management' ),
			);

			$response['success']         = true;
			$response['message']         = __( 'The sync setting for this post is disabled, so it will not be synchronized.', 'centralized-content-management' );
			$response['sync_status']     = __( 'Skipped', 'centralized-content-management' );
			$response['subsite_post_id'] = $subsite_post_id;
			$response['current_site_id'] = $current_site_id;
			$response['log_data']        = $log_data;

			return new WP_REST_Response( $response, 200 );
		}

		// Sync author if provided.
		if ( isset( $params['post_author'] ) && ! empty( $params['post_author'] ) ) {
			$central_user = new WP_User( (int) $params['post_author'], '', $central_site_id );

			if ( ! empty( $central_user ) ) {
				$user_role  = isset( $central_user->roles[0] ) ? $central_user->roles[0] : 'subscriber';
				$added_user = add_user_to_blog( $current_site_id, $central_user->ID, $user_role );

				if ( is_wp_error( $added_user ) ) {
					$response['success']       = false;
					$response['message']       = __( 'Error adding user to subsite.', 'centralized-content-management' );
					$response['debug_message'] = $added_user->get_error_message();
				} else {
					$post_data['post_author'] = $central_user->ID;
				}
			}
		}

		if ( empty( $subsite_post_id ) ) {
			// Insert new post.
			$subsite_post_id = wp_insert_post( $post_data );

			if ( is_wp_error( $subsite_post_id ) ) {
				// Log the sync operation.
				$log_data = array(
					'post_id'     => $central_post_id,
					'post_name'   => $post_data['post_title'],
					'site_id'     => $current_site_id,
					'site_name'   => Utils::get_blogname( $current_site_id ),
					'sync_time'   => current_time( 'mysql' ),
					'sync_status' => __( 'Failed', 'centralized-content-management' ),
					'sync_note'   => __( 'Error creating a post to subsite.', 'centralized-content-management' ),
				);

				// Set error response for post insertion.
				$response['success']         = false;
				$response['message']         = __( 'Error creating a post to subsite.', 'centralized-content-management' );
				$response['debug_message']   = $subsite_post_id->get_error_message();
				$response['sync_status']     = __( 'Failed', 'centralized-content-management' );
				$response['subsite_post_id'] = $subsite_post_id;
				$response['current_site_id'] = $current_site_id;
				$response['log_data']        = $log_data;
			} else {
				// Post inserted successfully.
				update_post_meta( $subsite_post_id, '_central_post_id', $central_post_id );

				// Log the sync operation.
				$log_data = array(
					'post_id'     => $central_post_id,
					'post_name'   => $post_data['post_title'],
					'site_id'     => $current_site_id,
					'site_name'   => Utils::get_blogname( $current_site_id ),
					'sync_time'   => current_time( 'mysql' ),
					'sync_status' => $sync_text,
					'sync_note'   => __( 'Post successfully created on the subsite.', 'centralized-content-management' ),
				);

				// Set success response for post insertion.
				$response['success']         = true;
				$response['message']         = __( 'Post successfully created on the subsite.', 'centralized-content-management' );
				$response['sync_status']     = $sync_text;
				$response['subsite_post_id'] = $subsite_post_id;
				$response['current_site_id'] = $current_site_id;
				$response['log_data']        = $log_data;
			}
		} else {
			// Update existing post.
			$post_data['ID'] = $subsite_post_id;
			$updated_post    = wp_update_post( $post_data );

			if ( is_wp_error( $updated_post ) ) {
				// Log the sync operation.
				$log_data = array(
					'post_id'     => $central_post_id,
					'post_name'   => $post_data['post_title'],
					'site_id'     => $current_site_id,
					'site_name'   => Utils::get_blogname( $current_site_id ),
					'sync_time'   => current_time( 'mysql' ),
					'sync_status' => __( 'Failed', 'centralized-content-management' ),
					'sync_note'   => __( 'Error updating the post on the subsite.', 'centralized-content-management' ),
				);

				// Set error response for post updation.
				$response['success']         = false;
				$response['message']         = __( 'Error updating the post on the subsite.', 'centralized-content-management' );
				$response['debug_message']   = $updated_post->get_error_message();
				$response['sync_status']     = __( 'Failed', 'centralized-content-management' );
				$response['subsite_post_id'] = $subsite_post_id;
				$response['current_site_id'] = $current_site_id;
				$response['log_data']        = $log_data;
			} else {
				// Post updated successfully.
				update_post_meta( $subsite_post_id, '_central_post_id', $central_post_id );

				// Log the sync operation.
				$log_data = array(
					'post_id'     => $central_post_id,
					'post_name'   => $post_data['post_title'],
					'site_id'     => $current_site_id,
					'site_name'   => Utils::get_blogname( $current_site_id ),
					'sync_time'   => current_time( 'mysql' ),
					'sync_status' => $sync_text,
					'sync_note'   => __( 'Post successfully updated on the subsite.', 'centralized-content-management' ),
				);

				// Set success response for post update.
				$response['success']         = true;
				$response['message']         = __( 'Post successfully updated on the subsite.', 'centralized-content-management' );
				$response['sync_status']     = $sync_text;
				$response['subsite_post_id'] = $subsite_post_id;
				$response['current_site_id'] = $current_site_id;
				$response['log_data']        = $log_data;
			}
		}

		// Update post meta.
		if ( isset( $params['meta_fields'] ) && ! empty( $params['meta_fields'] ) ) {
			// Remove all existing meta first.
			$new_target_post_meta = get_post_meta( $subsite_post_id );

			if ( ! empty( $new_target_post_meta ) && is_array( $new_target_post_meta ) ) {
				$new_target_post_meta_keys = array_keys( $new_target_post_meta );
				foreach ( $new_target_post_meta_keys as $new_target_post_meta_key ) {
					delete_post_meta( $new_target_post_meta, $new_target_post_meta_key );
				}
			}

			// Update with new values.
			foreach ( $params['meta_fields'] as $meta_key => $meta_value ) {
				$meta_value = maybe_unserialize( $meta_value );
				update_post_meta( $subsite_post_id, $meta_key, $meta_value );
			}
		}

		// Check for Yoast Primary Category.
		if ( isset( $params['meta_fields']['_yoast_wpseo_primary_category_slug'] ) && ! empty( $params['meta_fields']['_yoast_wpseo_primary_category_slug'] ) ) {
			// check if the term exists.
			$target_term = term_exists( $params['meta_fields']['_yoast_wpseo_primary_category_slug'], 'category' ); // phpcs:ignore
			if ( $target_term ) {
				$target_term_id = (int) $target_term['term_id'];
				update_post_meta( $subsite_post_id, '_yoast_wpseo_primary_category', $target_term_id );
				delete_post_meta( $subsite_post_id, '_yoast_wpseo_primary_category_slug' );
			}
		}

		// ACF fields meta.
		if ( isset( $params['acf_fields'] ) && ! empty( $params['acf_fields'] ) ) {
			foreach ( $params['acf_fields'] as $key => $field ) {
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
				update_post_meta( $subsite_post_id, $key, $acf_meta_data );
			}
		}

		// Sync taxonomies if provided.
		if ( isset( $params['taxonomies'] ) && ! empty( $params['taxonomies'] ) && ! empty( $subsite_post_id ) ) {
			foreach ( $params['taxonomies'] as $tax => $tax_terms ) {
				if ( taxonomy_exists( $tax ) ) {
					// Set post terms.
					Sync_Process::centralized_content_management_update_subsite_terms( $tax_terms, $subsite_post_id, $tax );
				}
			}
		}

		// Check post thumbnail exists.
		if ( ! empty( $central_images['thumbnail'] ) && ! empty( $central_images['thumbnail']['id'] ) ) {
			// Check if image exists based on central attachment ID.
			$existing_image = Sync_Process::centralized_content_management_find_attachment_by_central( $central_images['thumbnail']['id'] );

			if ( false !== $existing_image ) {
				// Set post thumbnail in subsite post.
				$thumbnail_id = $existing_image['id'];
				Sync_Process::centralized_content_management_set_post_thumbnail( $subsite_post_id, $thumbnail_id );
			} else {
				// Create new attachment in subsite.
				$added_image = Sync_Process::centralized_content_management_add_attachment_subsite( $central_site_id, $central_images['thumbnail']['id'], $central_images['thumbnail']['filepath'], $subsite_post_id, $central_images['thumbnail']['image_author'] );
				if ( isset( $added_image['id'] ) && ! empty( $added_image['id'] ) ) {
					// Set post thumbnail in subsite post.
					$thumbnail_id = $added_image['id'];
					Sync_Process::centralized_content_management_set_post_thumbnail( $subsite_post_id, $thumbnail_id );
				}
			}
		}

		$content_media = isset( $params['content_media'] ) ? $params['content_media'] : array();
		// Set single cron schedule for images.
		if ( ! empty( $content_media ) && ! wp_next_scheduled( 'centralized_content_management_subsite_sync_images_cron', array( $content_media, $subsite_post_id ) ) ) {
			wp_schedule_single_event( time(), 'centralized_content_management_subsite_sync_images_cron', array( $content_media, $subsite_post_id ) );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Saves ACF taxonomy relationship data.
	 *
	 * This function processes taxonomy relationship data from an ACF field value, checking
	 * if the term exists in the specified taxonomy. If the term exists, it returns the term ID(s).
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
	 * This function processes user relationship data from an ACF field value, checking
	 * if the user exists based on either their username or email. If the user exists,
	 * it returns the user ID(s).
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
	 * Changes the host of an image URL to match the target site's URL.
	 *
	 * This function replaces the host in the provided image URL with the target site's URL
	 * by swapping out the source URL with the target site URL.
	 *
	 * @param string $img_url    The full URL of the image, including the source site's host.
	 * @param string $source_url The source URL (host) to be replaced.
	 *
	 * @return string Returns the modified image URL with the target site's host.
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
				$existing_img_query = new WP_Query( $existing_img_args );

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

				$is_post_present = new WP_Query( $args_posts );

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
	 * Callback function to trash the post.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error The response or error.
	 */
	public function centralized_content_management_trash_post_api_callback( WP_REST_Request $request ) {
		$data     = $request->get_params();
		$response = array();

		// Check for empty post_id.
		if ( ! isset( $data['subsite_post_id'] ) || empty( $data['subsite_post_id'] ) ) {
			$response['message'] = __( 'Post ID is missing.', 'centralized-content-management' );
			$response['success'] = false;

			return new WP_REST_Response( $response, 200 );
		}

		// Retirve api parameters.
		$subsite_post_id   = isset( $data['subsite_post_id'] ) ? (int) $data['subsite_post_id'] : 0;
		$delete_on_subsite = isset( $data['delete_on_subsite'] ) ? (int) $data['delete_on_subsite'] : 0;
		$if_post_exists    = get_post( $subsite_post_id );

		if ( 0 === $delete_on_subsite ) {
			$response['message'] = __( 'This post should not be move to trash.', 'centralized-content-management' );
			$response['success'] = false;

			return new WP_REST_Response( $response, 200 );
		}

		if ( $if_post_exists ) {
			// Trash the post.
			$trashed_post = wp_trash_post( $subsite_post_id );

			if ( ! is_wp_error( $trashed_post ) ) {
				$response['message'] = __( 'Post successfully trashed.', 'centralized-content-management' );
				$response['success'] = true;
			} else {
				$response['message'] = __( 'Error trashing the post.', 'centralized-content-management' );
				$response['success'] = false;
			}
		} else {
			$response['message'] = __( 'Post not found!', 'centralized-content-management' );
			$response['success'] = false;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Callback function to untrash the post.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error The response or error.
	 */
	public function centralized_content_management_untrash_post_api_callback( WP_REST_Request $request ) {
		$data     = $request->get_params();
		$response = array();

		// Check for empty post_id.
		if ( ! isset( $data['subsite_post_id'] ) || empty( $data['subsite_post_id'] ) ) {
			$response['message'] = __( 'Post ID is missing.', 'centralized-content-management' );
			$response['success'] = false;

			return new WP_REST_Response( $response, 200 );
		}

		// Retirve api parameters.
		$subsite_post_id   = isset( $data['subsite_post_id'] ) ? (int) $data['subsite_post_id'] : 0;
		$delete_on_subsite = isset( $data['delete_on_subsite'] ) ? (int) $data['delete_on_subsite'] : 0;
		$if_post_exists    = get_post( $subsite_post_id );

		if ( 0 === $delete_on_subsite ) {
			$response['message'] = __( 'This post should not be move to untrash.', 'centralized-content-management' );
			$response['success'] = false;

			return new WP_REST_Response( $response, 200 );
		}

		if ( $if_post_exists ) {
			// Untrash the post.
			$untrashed_post = wp_untrash_post( $subsite_post_id );

			if ( ! is_wp_error( $untrashed_post ) ) {
				$response['message'] = __( 'Post successfully untrashed.', 'centralized-content-management' );
				$response['success'] = true;
			} else {
				$response['message'] = __( 'Error untrashing the post.', 'centralized-content-management' );
				$response['success'] = false;
			}
		} else {
			$response['message'] = __( 'Post not found!', 'centralized-content-management' );
			$response['success'] = false;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Callback function to delete the post.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error The response or error.
	 */
	public function centralized_content_management_delete_post_api_callback( WP_REST_Request $request ) {
		$data     = $request->get_params();
		$response = array();

		// Check for empty post_id.
		if ( ! isset( $data['subsite_post_id'] ) || empty( $data['subsite_post_id'] ) ) {
			$response['message'] = __( 'Post ID is missing.', 'centralized-content-management' );
			$response['success'] = false;

			return new WP_REST_Response( $response, 200 );
		}

		$subsite_post_id   = isset( $data['subsite_post_id'] ) ? (int) $data['subsite_post_id'] : 0;
		$delete_on_subsite = isset( $data['delete_on_subsite'] ) ? (int) $data['delete_on_subsite'] : 0;
		$if_post_exists    = get_post( $subsite_post_id );

		if ( 0 === $delete_on_subsite ) {
			$response['message'] = __( 'This post should not be delete.', 'centralized-content-management' );
			$response['success'] = false;

			return new WP_REST_Response( $response, 200 );
		}

		if ( $if_post_exists ) {
			// Delete the post.
			$deleted_post = wp_delete_post( $subsite_post_id, true );

			if ( ! is_wp_error( $deleted_post ) ) {
				$response['message'] = __( 'Post successfully deleted.', 'centralized-content-management' );
				$response['success'] = true;
			} else {
				$response['message'] = __( 'Error deleting the post.', 'centralized-content-management' );
				$response['success'] = false;
			}
		} else {
			$response['message'] = __( 'Post not found!', 'centralized-content-management' );
			$response['success'] = false;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Callback function to update synced data to central.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error The response or error.
	 */
	public function centralized_content_management_update_synced_data( WP_REST_Request $request ) {
		$response = array();
		$data     = $request->get_params();

		$central_post_id        = isset( $data['central_post_id'] ) ? (int) $data['central_post_id'] : 0;
		$subsite_synced_details = isset( $data['subsite_synced_data'] ) ? $data['subsite_synced_data'] : array();
		$subsite_id             = isset( $data['subsite_id'] ) ? (int) $data['subsite_id'] : 0;

		$synced_subsite_data                = get_post_meta( $central_post_id, '_synced_subsite_data', true );
		$synced_subsite_data                = ! empty( $synced_subsite_data ) && is_array( $synced_subsite_data ) ? $synced_subsite_data : array();
		$synced_subsite_data[ $subsite_id ] = $subsite_synced_details;

		update_post_meta( $central_post_id, '_synced_subsite_data', $synced_subsite_data );

		// Delete bulk sync site id temp meta.
        delete_post_meta( $central_post_id, '_bulk_sync_subsite_ids' );

		$response['message'] = __( 'Synced data updated successfully.', 'centralized-content-management' );
		$response['success'] = true;

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Authorizes the incoming request based on the provided API key.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return bool True if the request is authorized, false otherwise.
	 */
	public function centralized_content_management_authorize_request( $request ) {
		$api_key         = $request->get_header( 'x-api-key' );
		$api_keys        = get_network_option( null, 'centralized_content_management_api_keys', array() );
		$current_blog_id = get_current_blog_id();

		if ( isset( $api_keys[ $current_blog_id ] ) && $api_keys[ $current_blog_id ] === $api_key ) {
			return true;
		}

		return false;
	}
}

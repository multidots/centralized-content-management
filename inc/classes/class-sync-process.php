<?php
/**
 * Sync process for the MD Centralized Content Management plugin.
 *
 * This class handles the core logic and operations for managing content
 * across multiple subsites within the WordPress multisite network. It
 * provides methods for synchronizing post types, taxonomies, media,
 * user associations, and other content-related functionalities.
 * The Sync Process class acts as the main controller for the synchronization
 * feature, coordinating the interactions between the central site and its subsites.
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
class Sync_Process {

	use Singleton;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		// Check if the current site is the central site.
		if ( Utils::is_central_site() ) {
			$this->setup_sync_process_hooks();
		}
	}

	/**
	 * Function is used to define sync process hooks.
	 *
	 * @since   1.0.0
	 */
	public function setup_sync_process_hooks() {
		// Here...
	}

	/**
	 * Sync the post data to the selected subsites using the REST API.
	 *
	 * This function loops through the selected subsites and sends the post data to each subsite
	 * using a custom REST API endpoint. It handles success and error responses, logs the results,
	 * and stores the sync status in post meta for tracking.
	 *
	 * @param int   $post_id          The ID of the post being synced.
	 * @param array $selected_subsites An array of subsite IDs to which the post data will be synced.
	 * @param array $post_data        The data to be synced to the subsites, structured as an associative array.
	 *
	 * @return array Returns a summary of the sync process.
	 */
	public static function centralized_content_management_sync_process( $post_id, $selected_subsites, $post_data, $sync_type = 'single' ) {
		// Define WP_IMPORTING.
		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		// Init empty array for response.
		$summary      = array();
		$merged_array = array();
		$log_array    = array();

		// Set sync type while syncing process.
		$post_data['sync_type'] = $sync_type;

		// Send the data to each subsite via REST API.
		foreach ( $selected_subsites as $subsite_id ) {
			$endpoint_url = get_rest_url( $subsite_id ) . 'md-ccm/v1/sync-post';
			$api_key      = Utils::get_current_site_api_key( $subsite_id );

			// Send the post data to the subsite using wp_remote_post.
			$response = wp_remote_post(
				$endpoint_url,
				array(
					'body'    => wp_json_encode( $post_data ),
					'headers' => array(
						'Content-Type' => 'application/json',
						'X-API-KEY'    => $api_key,
					),
					'timeout' => 10,
				)
			);

			if ( is_wp_error( $response ) ) {
				$summary['success']       = false;
				$summary['message']       = __( 'Error syncing to subsite.', 'centralized-content-management' );
				$summary['debug_message'] = sprintf(
					'Error syncing to subsite ID %1$d: %2$s', 'centralized-content-management',
					$subsite_id,
					$response->get_error_message()
				);
			} else {
				// Get the response code and body.
				$response_code = wp_remote_retrieve_response_code( $response );
				$response_body = wp_remote_retrieve_body( $response );

				if ( 200 === $response_code ) {
					$response_data = json_decode( $response_body, true );

					// Store response data to summary array.
					$summary[]                   = $response_data;
					$merged_array[ $subsite_id ] = $response_data;

					// Retrive central log data.
					$central_post_id   = isset( $response_data['log_data'] ) ? $response_data['log_data']['post_id'] : 0;
					$current_site_id   = isset( $response_data['log_data'] ) ? $response_data['log_data']['site_id'] : 0;
					$central_post_name = isset( $response_data['log_data'] ) ? $response_data['log_data']['post_name'] : '';
					$sync_time         = isset( $response_data['log_data'] ) ? $response_data['log_data']['sync_time'] : '';
					$sync_status       = isset( $response_data['log_data'] ) ? $response_data['log_data']['sync_status'] : '';
					$sync_note         = isset( $response_data['log_data'] ) ? $response_data['log_data']['sync_note'] : '';

					// Initialize central_post_id in log_response if not already set
					if ( ! isset( $log_array[ $central_post_id ] ) ) {
						$log_array[ $central_post_id ] = array();
					}

					// Store current site ID under central_post_id.
					$log_array[ $central_post_id ][] = array(
						'post_name' => $central_post_name,
						'sync_data' => array(
							'site_id'     => $current_site_id,
							'sync_time'   => $sync_time,
							'sync_status' => $sync_status,
							'sync_note'   => $sync_note,
						),
					);
				} else {
					// Handle non-200 response codes.
					$summary['success']       = false;
					$summary['message']       = __( 'Error syncing to subsite.', 'centralized-content-management' );
					$summary['debug_message'] = sprintf(
						'Error syncing to subsite ID %1$d: HTTP Response Code %2$d', 'centralized-content-management',
						$subsite_id,
						intval( $response_code )
					);
				}
			}
		}

		if ( ! empty( $log_array ) ) {
			foreach ( $log_array as $the_post_id => $the_sync_data ) {
				// Prepare each post's data structure.
				$log_row = array(
					'post_id'    => $the_post_id,
					'post_name'  => $the_sync_data[0]['post_name'],
					'sync_sites' => array(),
				);

				// Collect sync_data details for each site.
				foreach ( $the_sync_data as $sync_entry ) {
					$log_row['sync_sites'][] = $sync_entry['sync_data'];
				}
			}

			// Insert all prepared data at once.
			Utils::insert_log( $log_row );
		}

		// Get synced subsite data and save synced subsites data to central site post meta table..
		$all_synced_subsite_data = get_post_meta( $post_id, '_synced_subsite_data', true );
		if ( is_array( $all_synced_subsite_data ) && ! empty( $all_synced_subsite_data ) ) {
			$all_synced_subsite_data = array_replace( $all_synced_subsite_data, $merged_array );
			update_post_meta( $post_id, '_synced_subsite_data', $all_synced_subsite_data );
		} else {
			$synced_subsite_data_array = array();
			$synced_subsite_data_array = array_replace( $synced_subsite_data_array, $merged_array );
			update_post_meta( $post_id, '_synced_subsite_data', $synced_subsite_data_array );
		}

		// Save synced subsites data to central site post meta table.
		// update_post_meta( $post_id, '_synced_subsite_data', $merged_array );

		return $summary;
	}

	/**
	 * Retrieve all media URLs and their corresponding attachment IDs from the post content.
	 *
	 * This function parses the content of a post to find all media (images) used in the post.
	 * It extracts the URLs of those media and then retrieves their corresponding attachment IDs
	 * from the WordPress database. The function returns an array containing the media URLs and IDs.
	 *
	 * @param int $post_id The ID of the post whose content is to be parsed.
	 *
	 * @return array An array containing media URLs and their corresponding attachment IDs.
	 *               Each element of the array is an associative array with 'url' (string)
	 *               and 'id' (int).
	 */
	public static function centralized_content_management_get_media_urls_and_ids_from_post_content( $post_content ) {
		$media_data = array();

		if ( preg_match_all( '/<img[^>]+src="([^">]+)"/i', $post_content, $matches ) ) {
			$media_urls = $matches[1]; // Array of media URLs.

			foreach ( $media_urls as $media_url ) {
				$full_size_media_url = $media_url;
				$item_img_sizes      = array();

				if ( preg_match( '/(-(\d+x\d+))\.(jpg|png|jpeg|gif){1}$/i', $media_url, $src_parts ) ) {
					$item_img_sizes = explode( 'x', $src_parts[2] );
				}

				// Get the attachment ID by the file URL
				$attachment_id    = self::get_attachment_id( $full_size_media_url );
				$central_filepath = get_attached_file( $attachment_id );
				$image_author     = get_post_field( 'post_author', $attachment_id );

				// Only add if an attachment ID is found
				if ( $attachment_id ) {
					$media_data[] = array(
						'url'      => $media_url,
						'id'       => $attachment_id,
						'full_url' => $full_size_media_url,
						'sizes'    => $item_img_sizes,
						'filepath' => $central_filepath,
						'author'   => $image_author,
					);
				}
			}
		}

		return $media_data;
	}

	/**
	 * Get an attachment ID given a URL.
	 *
	 * @param string $url
	 *
	 * @return int Attachment ID on success, 0 on failure
	 */
	public static function get_attachment_id( $url ) {
		$attachment_id = 0;
		$dir           = wp_upload_dir();

		if ( false !== strpos( $url, $dir['baseurl'] . '/' ) ) {
			$file = basename( $url );

			$query_args = array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'fields'      => 'ids',
				'meta_query'  => array( // phpcs:ignore
					array(
						'value'   => $file,
						'compare' => 'LIKE',
						'key'     => '_wp_attachment_metadata',
					),
				),
			);

			$query = new WP_Query( $query_args );

			if ( $query->have_posts() ) {
				foreach ( $query->posts as $post_id ) {
					$meta = wp_get_attachment_metadata( $post_id );

					$original_file       = basename( $meta['file'] );
					$cropped_image_files = wp_list_pluck( $meta['sizes'], 'file' );

					if ( $original_file === $file || in_array( $file, $cropped_image_files ) ) {
						$attachment_id = $post_id;
						break;
					}
				}
			}

			wp_reset_postdata();
		}

		return $attachment_id;
	}

	/**
	 * Get all taxonomies and their terms for a given post.
	 *
	 * @param int $post_id The ID of the post for which taxonomies and terms are to be retrieved.
	 *
	 * @return array An associative array where the keys are taxonomy names and the values are arrays of terms for each taxonomy.
	 */
	public static function centralized_content_management_get_taxonomies_for_post( $post_id ) {
		$centralized_content_management_settings = get_option( 'centralized_content_management_settings' );
		$selected_taxonomies  = isset( $centralized_content_management_settings['taxonomies'] ) ? $centralized_content_management_settings['taxonomies'] : array();
		$taxonomies           = get_object_taxonomies( get_post_type( $post_id ), 'names' );
		$terms                = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! in_array( $taxonomy, array_values( $selected_taxonomies ) ) ) {
				continue;
			}
			$central_terms      = get_the_terms( $post_id, $taxonomy );
			$terms[ $taxonomy ] = $central_terms;
		}

		return $terms;
	}

	/**
	 * This function use to update post terms.
	 *
	 * @param mixed  $central_post_categories Central site post taxonomy array.
	 * @param int    $subsite_post_id Child site post id.
	 * @param string $taxonomy Taxonomy name.
	 */
	public static function centralized_content_management_update_subsite_terms( $central_post_categories, $subsite_post_id, $taxonomy = 'category' ) {
		if ( ! empty( $central_post_categories ) && is_array( $central_post_categories ) ) {
			$terms_to_set = array();
			foreach ( $central_post_categories as $central_category ) {
				if ( empty( $central_category ) || ! isset( $central_category['slug'] ) ) {
					continue;
				}

				$subsite_term = get_term_by( 'slug', $central_category['slug'], $taxonomy );
				if ( empty( $subsite_term ) ) {
					$create_term = wp_insert_term(
						$central_category['name'],
						$taxonomy,
						array(
							'description' => isset( $central_category['description'] ) ? $central_category['description'] : '',
							'slug'        => isset( $central_category['slug'] ) ? $central_category['slug'] : '',
						)
					);

					if ( ! empty( $create_term ) ) {
						$terms_to_set[] = $create_term['term_id'];
					}
				} else {
					$terms_to_set[] = $subsite_term->term_id;
				}
			}
			if ( ! empty( $terms_to_set ) ) {
				wp_set_post_terms( $subsite_post_id, $terms_to_set, $taxonomy );
			}
		} else {
			wp_set_object_terms( $subsite_post_id, '', $taxonomy );
		}
	}

	/**
	 * This function is use to get child site attachment id from central site id.
	 *
	 * @param int $central_attachment_id Central attachment ID.
	 */
	public static function centralized_content_management_find_attachment_by_central( $central_attachment_id ) {
		$args = array(
			'post_type'      => array( 'attachment' ),
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
            'meta_query'     => array( // phpcs:ignore
				array(
					'key'   => '_central_attachment_id',
					'value' => $central_attachment_id,
				),
			),
		);

		$attchment_query = new WP_Query( $args );
		if ( 0 < $attchment_query->found_posts && ! empty( $attchment_query->posts[0] ) ) {
			return array(
				'id'  => $attchment_query->posts[0],
				'url' => wp_get_attachment_image_url( $attchment_query->posts[0], 'full' ),
			);
		} else {
			return false;
		}

		wp_reset_postdata();
	}

	/**
	 * This function is use to set post thumbnail.
	 *
	 * @param int $post_id Post ID.
	 * @param int $image_id Image ID.
	 */
	public static function centralized_content_management_set_post_thumbnail( $post_id, $image_id ) {
		set_post_thumbnail( $post_id, $image_id );
	}

	/**
	 * This function is use to add attachment in child site.
	 *
	 * @param int    $central_attach_id Central attachment ID.
	 * @param string $central_filepath Central filepath.
	 * @param int    $post_parent Subsite attachment parent to set.
	 * @param int    $post_author Subsite attachment author to set.
	 */
	public static function centralized_content_management_add_attachment_subsite( $central_site_id, $central_attach_id, $central_filepath, $post_parent = 0, $post_author = 0 ) {
		$current_blog_id = get_current_blog_id();
		if ( is_main_site( $central_site_id ) ) {
			$subsite_file = str_replace( '/uploads/', '/uploads/sites/' . $current_blog_id . '/', $central_filepath );
		} else {
			$subsite_file = str_replace( '/uploads/sites/' . $central_site_id . '/', '/uploads/sites/' . $current_blog_id . '/', $central_filepath );
		}

		// Need to verify if keeps this code on vip or not.
		wp_mkdir_p( dirname( $subsite_file ) );

		if ( ! empty( $central_filepath ) && copy( $central_filepath, $subsite_file ) ) {

			$image_meta = wp_read_image_metadata( $subsite_file );

			$post_data = array(
				'post_title'     => ( isset( $image_meta['title'] ) && ! empty( $image_meta['title'] ) ) ? $image_meta['title'] : preg_replace( '/\.[^.]+$/', '', wp_basename( $subsite_file ) ),
				'post_status'    => 'inherit',
				'post_type'      => 'attachment',
				'post_mime_type' => mime_content_type( $subsite_file ),
			);

			if ( ! empty( $post_parent ) ) {
				$post_data['post_parent'] = $post_parent;
			}

			if ( ! empty( $post_author ) ) {
				$post_data['post_author'] = $post_author;
			}

			$subsite_attach_id = wp_insert_attachment( $post_data, $subsite_file );

			if ( ! is_wp_error( $subsite_attach_id ) ) {
				$attachment_metadata = wp_generate_attachment_metadata( $subsite_attach_id, $subsite_file );
				wp_update_attachment_metadata( $subsite_attach_id, $attachment_metadata );

				// Update image relationship in central.
				update_post_meta( $subsite_attach_id, '_central_attachment_id', $central_attach_id );

				$subsite_image = array(
					'id'  => $subsite_attach_id,
					'url' => wp_get_attachment_image_url( $subsite_attach_id, 'full' ),
				);

				return $subsite_image;
			}
		}

		// If any errors then return false.
		return false;
	}

	/**
	 * This function used to find subsite post by central post.
	 *
	 * @param string $central_post_type Central post_type.
	 * @param int    $central_post_id Central post_id.
	 */
	public static function centralized_content_management_get_subsite_post( $central_post_type, $central_post_id ) {
		$args = array(
			'post_type'      => $central_post_type,
			'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private', 'trash' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'  => array( // phpcs:ignore
				array(
					'key'     => '_central_post_id',
					'value'   => $central_post_id,
					'compare' => '=',
				),
			),
		);

		$query = new WP_Query( $args );

		if ( 0 < $query->found_posts && ! empty( $query->posts[0] ) ) {
			return $query->posts[0];
		} else {
			return false;
		}

		wp_reset_postdata();
	}

	/**
	 * Find the attachment ID by URL from the media array.
	 *
	 * This function searches through an array of media data, where each element contains
	 * a 'url' and 'id' key. If the provided URL is found in the array, it returns the
	 * associated attachment ID. If not found, it returns false.
	 *
	 * @param string $url_to_find The URL to search for in the media array.
	 * @param array $media_array The array of media data to search through.
	 * @param string $return_type The type of value to return ('id', 'url', etc.).
	 *
	 * @return int|false The attachment ID if the URL is found, or false if not found.
	 */
	public static function centralized_content_management_find_media_id_by_url( $url_to_find, $media_array, $return_type = 'id' ) {
		// Loop through each item in the media array.
		foreach ( $media_array as $media_item ) {
			// Check if the 'url' matches the URL we are looking for.
			if ( isset( $media_item['url'] ) && $media_item['url'] === $url_to_find ) {
				// Return the requested value based on $return_type.
				switch ( $return_type ) {
					case 'id':
						return isset( $media_item['id'] ) ? $media_item['id'] : false;
					case 'url':
						return isset( $media_item['url'] ) ? $media_item['url'] : false;
					case 'full_url':
						return isset( $media_item['full_url'] ) ? $media_item['full_url'] : false;
					case 'sizes':
						return isset( $media_item['sizes'] ) ? $media_item['sizes'] : 'full';
					case 'filepath':
						return isset( $media_item['filepath'] ) ? $media_item['filepath'] : false;
					case 'author':
						return isset( $media_item['author'] ) ? $media_item['author'] : false;
					default:
						return false;
				}
			}
		}

		// If no match is found, return false.
		return false;
	}

	/**
	 * Prepares the ACF related data based on the field type.
	 *
	 * This function handles various ACF (Advanced Custom Fields) field types such as
	 * taxonomy, user, link, post object, relationship, page link, file, image, and gallery.
	 * It processes and prepares the respective related data for each field type.
	 *
	 * @param mixed  $acf_field_obj The ACF field object data to be processed.
	 * @param string $field_type    The type of ACF field to process (taxonomy, user, link, post_object, etc.).
	 *
	 * @return mixed Returns the processed ACF related data or an empty string if the field object or type is empty.
	 */
	public static function centralized_content_management_sync_prepare_acf_rel_data( $acf_field_obj, $field_type ) {
		if ( empty( $acf_field_obj ) || empty( $field_type ) ) {
			return '';
		}

		$acf_meta_data = '';

		switch ( $field_type ) {
			case 'taxonomy':
				$acf_meta_data = self::centralized_content_management_sync_prepare_tax_data( $acf_field_obj );
				break;
			case 'user':
				$acf_meta_data = self::centralized_content_management_sync_prepare_user_rel_data( $acf_field_obj );
				break;
			case 'link':
				$acf_meta_data = self::centralized_content_management_sync_prepare_link_rel_data( $acf_field_obj );
				break;
			case 'post_object':
			case 'relationship':
			case 'page_link':
			case 'file':
			case 'image':
			case 'gallery':
				$acf_meta_data = self::centralized_content_management_sync_prepare_post_rel_data( $acf_field_obj );
				break;
		}

		return $acf_meta_data;
	}

	/**
	 * Prepares ACF taxonomy field data for syncing.
	 *
	 * This function processes the taxonomy field data from an ACF field object and returns
	 * an array of term information such as name, slug, and taxonomy type. It handles both
	 * single and multiple term values.
	 *
	 * @param array $acf_field_obj The ACF field object containing taxonomy data.
	 *
	 * @return array|string Returns an array of term information if successful, or an empty string if no valid data is found.
	 */
	public static function centralized_content_management_sync_prepare_tax_data( $acf_field_obj ) {
		$meta_data = array();
		$value     = ( isset( $acf_field_obj['value'] ) ) ? $acf_field_obj['value'] : '';

		if ( empty( $value ) ) {
			return '';
		}

		$meta_data['type']  = $acf_field_obj['type'];
		$meta_data['value'] = array();
		$tax                = ( isset( $acf_field_obj['taxonomy'] ) ) ? $acf_field_obj['taxonomy'] : 'category';

		if ( is_array( $value ) ) {
			foreach ( $value as $acf_term_id ) {
				$acf_term = get_term( (int) $acf_term_id, $tax );
				if ( ! empty( $acf_term ) && ! is_wp_error( $acf_term ) ) {
					$single_term_arr             = array();
					$single_term_arr['name']     = $acf_term->name;
					$single_term_arr['slug']     = $acf_term->slug;
					$single_term_arr['taxonomy'] = $tax;

					array_push( $meta_data['value'], $single_term_arr );
				}
			}
		} else {
			$acf_term = get_term( (int) $value, $tax );
			if ( ! empty( $acf_term ) && ! is_wp_error( $acf_term ) ) {
				$single_term_arr             = array();
				$single_term_arr['name']     = $acf_term->name;
				$single_term_arr['slug']     = $acf_term->slug;
				$single_term_arr['taxonomy'] = $tax;

				array_push( $meta_data['value'], $single_term_arr );
			}
		}

		return $meta_data;
	}

	/**
	 * Prepares ACF user relationship data for syncing.
	 *
	 * This function processes the user field data from an ACF field object and returns
	 * an array containing user information such as user login and user email. It handles
	 * both single and multiple user values.
	 *
	 * @param array $acf_field_obj The ACF field object containing user relationship data.
	 *
	 * @return array|string Returns an array of user information if successful, or an empty string if no valid data is found.
	 */
	public static function centralized_content_management_sync_prepare_user_rel_data( $acf_field_obj ) {
		$meta_data = array();
		$value     = ( isset( $acf_field_obj['value'] ) ) ? $acf_field_obj['value'] : '';

		if ( empty( $value ) ) {
			return '';
		}

		$meta_data['type']  = $acf_field_obj['type'];
		$meta_data['value'] = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $acf_user_id ) {
				$acf_user = get_user_by( 'ID', (int) $acf_user_id );
				if ( ! empty( $acf_user ) ) {
					$single_user_arr               = array();
					$single_user_arr['user_login'] = $acf_user->user_login;
					$single_user_arr['user_email'] = $acf_user->user_email;

					array_push( $meta_data['value'], $single_user_arr );
				}
			}
		} else {
			$acf_user = get_user_by( 'ID', (int) $value );
			if ( ! empty( $acf_user ) ) {
				$single_user_arr               = array();
				$single_user_arr['user_login'] = $acf_user->user_login;
				$single_user_arr['user_email'] = $acf_user->user_email;

				array_push( $meta_data['value'], $single_user_arr );
			}
		}

		return $meta_data;
	}

	/**
	 * Prepares ACF link field data for syncing.
	 *
	 * This function processes the link field data from an ACF field object and returns
	 * an array of link information, including the URL, title, and target.
	 *
	 * @param array $acf_field_obj The ACF field object containing link data.
	 *
	 * @return array|string Returns an array with link information if successful, or an empty string if no valid data is found.
	 */
	public static function centralized_content_management_sync_prepare_link_rel_data( $acf_field_obj ) {
		$meta_data = array();
		$value     = ( isset( $acf_field_obj['value'] ) ) ? $acf_field_obj['value'] : '';

		if ( empty( $value ) ) {
			return '';
		}

		$meta_data['type']  = $acf_field_obj['type'];
		$meta_data['value'] = array(
			'url'    => $value['url'],
			'title'  => $value['title'],
			'target' => $value['target'],
		);

		return $meta_data;
	}

	/**
	 * Prepares ACF post relationship field data for syncing.
	 *
	 * This function processes post-related data from an ACF field object, which may contain
	 * references to posts, attachments, or other post types. It returns the post ID, post type,
	 * and for attachments, the URL.
	 *
	 * @param array $acf_field_obj The ACF field object containing post relationship data.
	 *
	 * @return array|string Returns an array with post relationship information if successful, or an empty string if no valid data is found.
	 */
	public static function centralized_content_management_sync_prepare_post_rel_data( $acf_field_obj ) {
		$meta_data = array();
		$value     = ( isset( $acf_field_obj['value'] ) ) ? $acf_field_obj['value'] : '';

		if ( empty( $value ) ) {
			return '';
		}

		$meta_data['type']  = $acf_field_obj['type'];
		$meta_data['value'] = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $acf_post_id ) {
				$acf_post = get_post( (int) $acf_post_id );
				if ( ! empty( $acf_post ) && ! is_wp_error( $acf_post ) ) {
					$single_post_arr              = array();
					$single_post_arr['ID']        = $acf_post->ID;
					$single_post_arr['post_type'] = $acf_post->post_type;

					if ( 'attachment' === $acf_post->post_type ) {
						$single_post_arr['url'] = wp_get_attachment_url( $acf_post_id );
					} else {
						$single_post_arr['post_name'] = $acf_post->post_name;
					}

					array_push( $meta_data['value'], $single_post_arr );
				}
			}
		} else {
			$acf_post = get_post( (int) $value );
			if ( ! empty( $acf_post ) && ! is_wp_error( $acf_post ) ) {
				$single_post_arr              = array();
				$single_post_arr['ID']        = $acf_post->ID;
				$single_post_arr['post_type'] = $acf_post->post_type;

				if ( 'attachment' === $acf_post->post_type ) {
					$single_post_arr['url'] = wp_get_attachment_url( $value );
				} else {
					$single_post_arr['post_name'] = $acf_post->post_name;
				}

				array_push( $meta_data['value'], $single_post_arr );
			}
		}

		return $meta_data;
	}
}

<?php
/**
 * Content Queue Helper for MD Centralized Content Management plugin.
 *
 * This helper class provides utility functions for managing the content queue
 * for synchronization between the central site and subsites in a WordPress multisite network.
 * It helps manage tasks related to the content synchronization process, such as retrieving,
 * adding, and updating entries in the central synchronization queue table.
 *
 * @package    Centralized_Content_Management
 * @subpackage Centralized_Content_Management/Inc
 * @since      1.0.0
 */

namespace Centralized_Content_Management\Inc;

use Centralized_Content_Management\Inc\Traits\Singleton;

/**
 * Content_Queue_Helper class.
 */
class Content_Queue_Helper {

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
	 * Prepares a post object for synchronization, including title, content, media, etc.
	 *
	 * @param int    $post_id 	Post ID.
	 * @param object $post    	Post object.
	 * @param string $sync_type Sync Type.
	 *
	 * @return array Prepared post data for synchronization.
	 *
	 * @since 1.0.0
	 */
	public static function centralized_content_management_sync_prepare_post_object( $post_id, $post, $sync_proccess_type = 'single' ) {
		$response            = array();
		$post_object         = array();
		$post_object_compare = array();

		// Post Type
		$post_type = isset( $post->post_type ) ? $post->post_type : '';

		// Post Title
		$post_object['title']         = isset( $post->post_title ) ? $post->post_title : '';
		$post_object_compare['title'] = array(
			'label' => 'Title',
			'value' => $post_object['title'],
		);

		// Post Slug
		$post_object['post_name']         = isset( $post->post_name ) ? $post->post_name : '';
		$post_object_compare['post_name'] = array(
			'label' => 'Post Name',
			'value' => $post_object['post_name'],
		);

		// Post Author
		$post_object['post_author'] = isset( $post->post_author ) ? $post->post_author : get_current_user_id();

		// Post Content
		$post_object['content']          = isset( $post->post_content ) ? $post->post_content : '';
		$post_object['content_filtered'] = isset( $post->post_content_filtered ) ? $post->post_content_filtered : '';
		$post_object_compare['content']  = array(
			'label' => 'Content',
			'value' => $post_object['content'],
		);

		// Featured Image
		if ( has_post_thumbnail( $post ) ) {
			$central_thumbnail_id          = get_post_thumbnail_id( $post );
			$post_object['featured_image'] = array(
				'id'           => $central_thumbnail_id,
				'filepath'     => get_attached_file( $central_thumbnail_id ),
				'url'          => wp_get_attachment_image_url( $central_thumbnail_id, 'full' ),
				'image_author' => get_post_field( 'post_author', $central_thumbnail_id ),
			);

			$post_object_compare['featured_image_url'] = array(
				'label' => 'Featured Image URL',
				'value' => $post_object['featured_image']['url'],
			);
		}

		// Content Media
		$post_object['content_media'] = self::centralized_content_management_get_media_urls_and_ids_from_post_content( $post_object['content'] );

		// Post Status
		$post_object['post_status']         = isset( $post->post_status ) ? $post->post_status : '';
		$post_object_compare['post_status'] = array(
			'label' => 'Post Status',
			'value' => $post_object['post_status'],
		);

		// Post Taxonomies
		$post_taxonomies = array();
		$taxonomies      = get_object_taxonomies( $post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$assigned_terms = get_the_terms( $post_id, $taxonomy );
			$post_terms     = array();

			if ( ! empty( $assigned_terms ) && ! is_wp_error( $assigned_terms ) ) {
				$taxonomy_obj = get_taxonomy( $taxonomy );
				foreach ( $assigned_terms as $assigned_term ) {
					$single_term_arr         = array();
					$single_term_arr['name'] = $assigned_term->name;
					$single_term_arr['slug'] = $assigned_term->slug;
					array_push( $post_terms, $single_term_arr );
				}

				$assigned_terms_names              = wp_list_pluck( $assigned_terms, 'name' );
				$post_taxonomies[ $taxonomy ]      = $post_terms;
				$post_object_compare [ $taxonomy ] = array(
					'label' => $taxonomy_obj->labels->singular_name ?? $taxonomy,
					'value' => implode( ', ', $assigned_terms_names ),
				);
			} else {
				$post_taxonomies[ $taxonomy ] = array();
			}
		}

		$post_object['taxonomy'] = $post_taxonomies;

		// Sync proccess.
		$post_object['sync_proccess_type'] = $sync_proccess_type;

		// Post Meta.
		$post_meta_object              = array();
		$post_meta_object_compare      = array();
		$acf_meta_object               = array();
		$post_data['post_meta_object'] = array();
		$post_data['acf_fields']       = array();
		$meta_fields                   = get_post_meta( $post_id, '', true );

		if ( ! empty( $meta_fields ) ) {
			$not_to_be_synced = array(
				'_edit_lock',
				'_edit_last',
				'_thumbnail_id',
				'_ccm_selected_subsites',
				'_ccm_disable_sync',
				'_synced_subsite_data',
				'_pingme',
				'_encloseme',
			);

			$acf_relational_fields = array( 'link', 'post_object', 'taxonomy', 'user', 'relationship', 'page_link', 'file', 'image', 'gallery' );

			foreach ( $meta_fields as $meta_key => $meta_val ) {
				if ( false === in_array( $meta_key, $not_to_be_synced, true ) && false === strpos( $meta_val[0], 'field_' ) ) {

					if ( function_exists( 'get_field_object' ) ) {
						// Check if the field is ACF field
						$acf_field_obj = get_field_object( $meta_key, $post_id, false );

						if ( ! empty( $acf_field_obj ) && isset( $acf_field_obj['type'] ) ) {

							$field_type = $acf_field_obj['type'];
							if ( in_array( $field_type, $acf_relational_fields, true ) ) {
								$acf_field_obj_response                = self::centralized_content_management_queue_prepare_acf_rel_data( $acf_field_obj, $field_type );
								$acf_meta_object[ $meta_key ]          = $acf_field_obj_response['post_meta_object'];
								$post_meta_object_compare[ $meta_key ] = array(
									'label' => $acf_field_obj['label'] ?? $meta_key,
									'value' => $acf_field_obj_response['post_meta_object_compare'],
								);
							} else {
								if ( '_yoast_wpseo_primary_category' === $meta_key && ! empty( $meta_val[0] ) ) {
									$primary_term_id = (int) $meta_val[0];
									$primary_cat     = get_term( $primary_term_id, 'category' );
									if ( ! empty( $primary_cat ) && ! is_wp_error( $primary_cat ) ) {
										$post_meta_object['_yoast_wpseo_primary_category_slug']         = $primary_cat->slug;
										$post_meta_object_compare['_yoast_wpseo_primary_category_slug'] = array(
											'label' => 'Yoast Primary Category',
											'value' => $primary_cat->name,
										);
									} else {
										$post_meta_object[ $meta_key ]         = $meta_val[0];
										$meta_val_compare                      = maybe_unserialize( $meta_val[0] );
										$meta_val_compare                      = is_array( $meta_val_compare ) ? implode( ', ', $meta_val_compare ) : $meta_val_compare;
										$post_meta_object_compare[ $meta_key ] = array(
											'label' => $meta_key,
											'value' => $meta_val_compare,
										);
									}
								} else {
									$post_meta_object[ $meta_key ]         = $meta_val[0];
									$meta_val_compare                      = maybe_unserialize( $meta_val[0] );
									$meta_val_compare                      = is_array( $meta_val_compare ) ? implode( ', ', self::centralized_content_management_flatten_array( $meta_val_compare ) ) : $meta_val_compare;
									$post_meta_object_compare[ $meta_key ] = array(
										'label' => $acf_field_obj['label'] ?? $meta_key,
										'value' => $meta_val_compare,
									);
								}
							}
						} else {
							$post_meta_object[ $meta_key ]         = $meta_val[0];
							$meta_val_compare                      = maybe_unserialize( $meta_val[0] );
							$meta_val_compare                      = is_array( $meta_val_compare ) ? implode( ', ', self::centralized_content_management_flatten_array( $meta_val_compare ) ) : $meta_val_compare;
							$post_meta_object_compare[ $meta_key ] = array(
								'label' => $meta_key,
								'value' => $meta_val_compare,
							);
						}
					} else {
						$post_meta_object[ $meta_key ]         = $meta_val[0];
						$meta_val_compare                      = maybe_unserialize( $meta_val[0] );
						$meta_val_compare                      = is_array( $meta_val_compare ) ? implode( ', ', self::centralized_content_management_flatten_array( $meta_val_compare ) ) : $meta_val_compare;
						$post_meta_object_compare[ $meta_key ] = array(
							'label' => $meta_key,
							'value' => $meta_val_compare,
						);
					}
				}
			}
		}

		$post_object['meta_fields'] = $post_meta_object;
		$post_object['acf_fields']  = $acf_meta_object;
		$post_object_compare        = array_merge( $post_object_compare, $post_meta_object_compare );

		$response['post_object']         = wp_json_encode( $post_object );
		$response['post_object_compare'] = wp_json_encode( $post_object_compare );

		return $response;
	}

	/**
	 * Extracts media URLs and IDs from the post content.
	 *
	 * @param string $content Post content.
	 *
	 * @return array Array of media URLs and their attachment IDs.
	 *
	 * @since 1.0.0
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
				$attachment_id    = Sync_Process::get_attachment_id( $full_size_media_url );
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
	 * Prepares ACF relational data for synchronization.
	 *
	 * @param object $acf_field_obj ACF field object.
	 * @param string $field_type    Type of ACF field.
	 *
	 * @return array Prepared ACF relational data.
	 *
	 * @since 1.0.0
	 */
	public static function centralized_content_management_queue_prepare_acf_rel_data( $acf_field_obj, $field_type ) {
		$acf_post_object                             = array();
		$acf_post_object['post_meta_object']         = array();
		$acf_post_object['post_meta_object_compare'] = '';
		if ( empty( $acf_field_obj ) || empty( $field_type ) ) {
			return $acf_post_object;
		}

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

		$acf_post_object['post_meta_object']         = $acf_meta_data['post_meta_object'] ?? '';
		$acf_post_object['post_meta_object_compare'] = $acf_meta_data['post_meta_object_compare'] ?? '';

		return $acf_post_object;
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
	private static function centralized_content_management_sync_prepare_tax_data( $acf_field_obj ) {
		$prepared_meta_response = array();
		$meta_data              = array();
		$value                  = ( isset( $acf_field_obj['value'] ) ) ? $acf_field_obj['value'] : '';

		if ( empty( $value ) ) {
			return $prepared_meta_response;
		}

		$meta_data['type']  = $acf_field_obj['type'];
		$meta_data['value'] = array();
		$tax                = ( isset( $acf_field_obj['taxonomy'] ) ) ? $acf_field_obj['taxonomy'] : 'category';

		if ( is_array( $value ) ) {
			foreach ( $value as $acf_term_id ) {
				$acf_term   = get_term( (int) $acf_term_id, $tax );
				$term_names = array();
				if ( ! empty( $acf_term ) && ! is_wp_error( $acf_term ) ) {
					$single_term_arr             = array();
					$single_term_arr['name']     = $acf_term->name;
					$single_term_arr['slug']     = $acf_term->slug;
					$single_term_arr['taxonomy'] = $tax;
					$term_names[]                = $acf_term->name;

					array_push( $meta_data['value'], $single_term_arr );
				}
				$prepared_meta_response['post_meta_object']         = $meta_data;
				$prepared_meta_response['post_meta_object_compare'] = implode( ', ', $term_names );
			}
		} else {
			$acf_term = get_term( (int) $value, $tax );
			if ( ! empty( $acf_term ) && ! is_wp_error( $acf_term ) ) {
				$single_term_arr             = array();
				$single_term_arr['name']     = $acf_term->name;
				$single_term_arr['slug']     = $acf_term->slug;
				$single_term_arr['taxonomy'] = $tax;

				array_push( $meta_data['value'], $single_term_arr );
				$prepared_meta_response['post_meta_object']         = $meta_data;
				$prepared_meta_response['post_meta_object_compare'] = $single_term_arr['name'];
			}
		}

		return $prepared_meta_response;
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
	private static function centralized_content_management_sync_prepare_user_rel_data( $acf_field_obj ) {
		$prepared_meta_response = array();
		$meta_data              = array();
		$value                  = ( isset( $acf_field_obj['value'] ) ) ? $acf_field_obj['value'] : '';

		if ( empty( $value ) ) {
			return $prepared_meta_response;
		}

		$meta_data['type']  = $acf_field_obj['type'];
		$meta_data['value'] = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $acf_user_id ) {
				$acf_user_names = array();
				$acf_user       = get_user_by( 'ID', (int) $acf_user_id );
				if ( ! empty( $acf_user ) ) {
					$single_user_arr               = array();
					$single_user_arr['user_login'] = $acf_user->user_login;
					$single_user_arr['user_email'] = $acf_user->user_email;
					array_push( $meta_data['value'], $single_user_arr );
					$acf_user_names[] = $acf_user->user_login;
				}
				$prepared_meta_response['post_meta_object']         = $meta_data;
				$prepared_meta_response['post_meta_object_compare'] = implode( ', ', $acf_user_names );
			}
		} else {
			$acf_user = get_user_by( 'ID', (int) $value );
			if ( ! empty( $acf_user ) ) {
				$single_user_arr               = array();
				$single_user_arr['user_login'] = $acf_user->user_login;
				$single_user_arr['user_email'] = $acf_user->user_email;
				array_push( $meta_data['value'], $single_user_arr );
				$prepared_meta_response['post_meta_object']         = $meta_data;
				$prepared_meta_response['post_meta_object_compare'] = $single_user_arr['user_login'];
			}
		}

		return $prepared_meta_response;
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
	private static function centralized_content_management_sync_prepare_link_rel_data( $acf_field_obj ) {
		$prepared_meta_response = array();
		$meta_data              = array();
		$value                  = ( isset( $acf_field_obj['value'] ) ) ? $acf_field_obj['value'] : '';

		if ( empty( $value ) ) {
			return $prepared_meta_response;
		}

		$meta_data['type']                                  = $acf_field_obj['type'];
		$meta_data['value']                                 = array(
			'url'    => $value['url'],
			'title'  => $value['title'],
			'target' => $value['target'],
		);
		$prepared_meta_response['post_meta_object']         = $meta_data;
		$prepared_meta_response['post_meta_object_compare'] = 'Title: ' . $value['title'] . ', URL: ' . $value['url'] . ', Target: ' . $value['target'];

		return $prepared_meta_response;
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
	private static function centralized_content_management_sync_prepare_post_rel_data( $acf_field_obj ) {
		$prepared_meta_response = array();
		$meta_data              = array();
		$value                  = ( isset( $acf_field_obj['value'] ) ) ? $acf_field_obj['value'] : '';

		if ( empty( $value ) ) {
			return '';
		}

		$meta_data['type']  = $acf_field_obj['type'];
		$meta_data['value'] = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $acf_post_id ) {
				$acf_post  = get_post( (int) $acf_post_id );
				$rel_posts = array();
				if ( ! empty( $acf_post ) && ! is_wp_error( $acf_post ) ) {
					$single_post_arr              = array();
					$single_post_arr['ID']        = $acf_post->ID;
					$single_post_arr['post_type'] = $acf_post->post_type;
					if ( 'attachment' === $acf_post->post_type ) {
						$single_post_arr['url'] = wp_get_attachment_url( $acf_post_id );
						$rel_posts[]            = $single_post_arr['url'];
					} else {
						$single_post_arr['post_name'] = $acf_post->post_name;
						$rel_posts[]                  = $acf_post->post_name;
					}
					array_push( $meta_data['value'], $single_post_arr );
				}
				$prepared_meta_response['post_meta_object']         = $meta_data;
				$prepared_meta_response['post_meta_object_compare'] = implode( ', ', $rel_posts );
			}
		} else {
			$acf_post = get_post( (int) $value );
			if ( ! empty( $acf_post ) && ! is_wp_error( $acf_post ) ) {
				$single_post_arr              = array();
				$single_post_arr['ID']        = $acf_post->ID;
				$single_post_arr['post_type'] = $acf_post->post_type;
				if ( 'attachment' === $acf_post->post_type ) {
					$single_post_arr['url']                             = wp_get_attachment_url( $value );
					$prepared_meta_response['post_meta_object_compare'] = $single_post_arr['url'];
				} else {
					$single_post_arr['post_name']                       = $acf_post->post_name;
					$prepared_meta_response['post_meta_object_compare'] = $single_post_arr['post_name'];
				}
				array_push( $meta_data['value'], $single_post_arr );
				$prepared_meta_response['post_meta_object'] = $meta_data;
			}
		}

		return $prepared_meta_response;
	}

	/**
	 * Updates the synced data on the central site by making a REST API request.
	 *
	 * @param int   $central_post_id    The post ID from the central site.
	 * @param array $subsite_synced_data An array containing the synced data from the subsite.
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	public static function centralized_content_management_central_update_synced_data( $central_post_id, $subsite_synced_data ) {
		// Make REST API request to central site endpoint.
		$central_site_id       = Utils::get_central_site_id();
		$central_site_rest_url = get_rest_url( $central_site_id ) . 'md-ccm/v1/update-synced-data';
		$api_key               = Utils::get_current_site_api_key( $central_site_id );
		$body                  = array(
			'central_post_id'     => $central_post_id,
			'subsite_synced_data' => $subsite_synced_data,
			'subsite_id'          => get_current_blog_id(),
		);

		$response = wp_remote_post(
			$central_site_rest_url,
			array(
				'body'    => wp_json_encode( $body ),
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-KEY'    => $api_key,
				),
				'timeout' => 10, // phpcs:ignore
			)
		);
	}

	/**
	 * Retrieves the name of the central queue table for the central site.
	 *
	 * @return string The name of the central queue table.
	 *
	 * @since 1.0.0
	 */
	public static function centralized_content_management_get_central_queue_table() {
		global $wpdb;

		$central_site_id     = Utils::get_central_site_id();
		$central_site_prefix = $wpdb->get_blog_prefix( $central_site_id );
		$central_queue_table = $central_site_prefix . 'ccm_central_queue';

		return $central_queue_table;
	}

	/**
	 * Flattens a multidimensional array into a single-dimensional array.
	 *
	 * This function recursively traverses a multidimensional array and extracts all values,
	 * returning them as a flat, one-dimensional array.
	 *
	 * @param array $array The multidimensional array to be flattened.
	 *
	 * @return array The flattened array containing all values from the original array.
	 */
	public static function centralized_content_management_flatten_array( $array ) {
		$flattened = array();
		array_walk_recursive(
			$array,
			function( $value ) use ( &$flattened ) {
				$flattened[] = $value;
			}
		);

		return $flattened;
	}
}

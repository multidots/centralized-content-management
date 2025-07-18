<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
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
class Admin {

	use Singleton;

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'CENTRALIZED_CONTENT_MANAGEMENT_VERSION' ) ) {
			$this->version = CENTRALIZED_CONTENT_MANAGEMENT_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->setup_admin_hooks();
	}

	/**
	 * Function is used to define admin hooks.
	 *
	 * @since   1.0.0
	 */
	public function setup_admin_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'https_ssl_verify', '__return_false' );
		add_action( 'centralized_content_management_subsite_sync_images_cron', array( $this, 'centralized_content_management_subsite_sync_images_cron_callback' ), 10, 2 );
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {
		wp_enqueue_style( 'centralized-content-management', CENTRALIZED_CONTENT_MANAGEMENT_URL . 'assets/build/admin.css', array(), $this->version, 'all' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {
		
		//wp_enqueue_script( 'jsticky-mode', CENTRALIZED_CONTENT_MANAGEMENT_URL . 'assets/src/js/jquery.jsticky.mod.min.js', array( 'jquery' ), $this->version, false );

		$assets_file_path = CENTRALIZED_CONTENT_MANAGEMENT_DIR . 'assets/build/admin.asset.php';
		$assets           = file_exists( $assets_file_path ) ? include $assets_file_path : array(
			'dependencies' => array(),
			'version'      => $this->version,
		);
		$version          = isset( $assets['version'] ) ? $assets['version'] : $this->version;

		wp_enqueue_script( 'centralized-content-management', CENTRALIZED_CONTENT_MANAGEMENT_URL . 'assets/build/admin.js', array( 'jquery' ), $version, false );
		// Initialize the post IDs array
		$restricted_post_ids = array();

		// Get the current screen and central settings
		$screen = get_current_screen();
		$subsite_option_data = get_option( 'central_setting_data' );

		if ( isset( $subsite_option_data['allow_modification'] ) && ! $subsite_option_data['allow_modification'] ) {
			if ( isset( $subsite_option_data['post_types'] ) ) {
				foreach ( $subsite_option_data['post_types'] as $post_type ) {
					// Check if we're on the edit screen for this post type
					if ( 'edit-' . $post_type === $screen->id ) {
						$post_obj = get_posts(
							array(
								'post_type'   => $post_type,
								'numberposts' => -1,
								'fields'      => 'ids',
								'post_status' => 'any'
							)
						);

						if ( ! empty( $post_obj ) && is_array( $post_obj ) ) {
							foreach ( $post_obj as $post_id ) {
								// Get the central post ID for the current post
								$central_post_id = get_post_meta( $post_id, '_central_post_id', true );

								if ( $central_post_id ) {
									// Add the post ID to the restricted post IDs array
									$restricted_post_ids[] = $post_id;
								}
							}
						}
					}
				}
			}
		}

		// Localize the script to pass the necessary data to JavaScript
		wp_localize_script(
			'centralized-content-management', // The handle of your script
			'siteConfig', // The JavaScript object name
			array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'ajaxNonce'         => wp_create_nonce( 'restrict_post_modification_nonce' ),
				'restrictedPosts'   => $restricted_post_ids, // Pass the restricted post IDs
			)
		);

	}

	/**
	 * Callback function for syncing post content images via cron job.
	 *
	 * This function retrieves the content of a post from a subsite, replaces
	 * the image URLs with the corresponding URLs from the subsite, and syncs
	 * the images from the central site to the subsite. If an image does not
	 * exist on the subsite, it creates a new attachment for it. The updated
	 * content is then saved back to the post on the subsite.
	 *
	 * @param array $content_media    Media data from the central site to be synced.
	 * @param int   $subsite_post_id  The ID of the subsite post where the content is updated.
	 */
	public function centralized_content_management_subsite_sync_images_cron_callback( $content_media, $subsite_post_id ) {
		// Define WP_IMPORTING.
		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		// Get the post content of subsite's post.
		$post_content     = get_post_field( 'post_content', $subsite_post_id );
		$central_site_url = get_site_url( Utils::centralized_content_management_get_central_site_id() );

		// Replace image urls.
		$updated_post_content = preg_replace_callback(
			'/<!--\s*wp:image(.*)-->.*<img.*src="(.*)".*\/>(.*)<!-- \/wp:image\s*-->/sU', // phpcs:ignore
			function ( $matches ) use ( $subsite_post_id, $content_media ) {
				if ( ! empty( $matches ) ) {
					$img         = ( isset( $matches[0] ) ) ? $matches[0] : '';
					$image_json  = ( isset( $matches[1] ) ) ? $matches[1] : '';
					$image_url   = ( isset( $matches[2] ) ) ? urldecode( $matches[2] ) : '';
					$img_query_p = '';

					// Get content media id by image url.
					$central_content_media_id = Sync_Process::centralized_content_management_find_media_id_by_url( $image_url, $content_media, 'id' );

					$is_query_p = strpos( $image_url, '?' );
					if ( false !== $is_query_p ) {
						$img_query_p = substr( $image_url, $is_query_p );
					}

					$base_url   = home_url();
					$image_data = array();
					if ( ! empty( $image_json ) ) {
						$image_data = json_decode( $image_json );
					}

					// Get alt text from central site image.
					preg_match( '/<img.*alt="(.*)".*\/>/sU', $img, $img_matches );
					$img_alt = isset( $img_matches[1] ) ? $img_matches[1] : '';

					// Get additioinal styles and figure classes.
					preg_match( '/<figure.*class="(.*)".*>.*<\/figure>/sU', $img, $figure_class_matches );
					$figure_classes = isset( $figure_class_matches[1] ) && ! empty( $figure_class_matches[1] ) ? $figure_class_matches[1] : 'wp-block-image size-large';

					// Get style attribute of img tag.
					preg_match( '/<figure.*><img.*style="(.*)".*><\/figure>/sU', $img, $img_style_matches );
					$img_style = isset( $img_style_matches[1] ) && ! empty( $img_style_matches[1] ) ? $img_style_matches[1] : '';

					if ( false === strpos( $img, $base_url ) && ! empty( $image_data ) && isset( $image_data->id ) ) {
						// Check if image exists based on central attachment ID.
						$existing_image = Sync_Process::centralized_content_management_find_attachment_by_central( $central_content_media_id );

						if ( false !== $existing_image ) {
							$subsite_content_images = $existing_image;
						} else {
							// Get central filepath and image author by image url.
							$central_filepath = Sync_Process::centralized_content_management_find_media_id_by_url( $image_url, $content_media, 'filepath' );
							$image_author     = Sync_Process::centralized_content_management_find_media_id_by_url( $image_url, $content_media, 'author' );

							// Create new attachment in subsite.
							$subsite_content_images = Sync_Process::centralized_content_management_add_attachment_subsite( Utils::centralized_content_management_get_central_site_id(), $central_content_media_id, $central_filepath, $subsite_post_id, $image_author );
						}
						if ( isset( $subsite_content_images['id'] ) && ! empty( $subsite_content_images['id'] ) ) {
							$subsite_img_id = $subsite_content_images['id'];
							$img_sizes      = Sync_Process::centralized_content_management_find_media_id_by_url( $image_url, $content_media, 'sizes' );
							$new_image_url  = wp_get_attachment_image_url( $subsite_img_id, $img_sizes );

							// Append existing querystring in image url.
							$new_image_url .= $img_query_p;

							// Create figure and img tag.
							if ( ! empty( trim( $img_style ) ) ) {
								$figure_tag = '<figure class="' . $figure_classes . '"><img src="' . $new_image_url . '" alt="' . $img_alt . '" class="wp-image-' . $subsite_img_id . '" style="' . $img_style . '" />'; // phpcs:ignore
							} else {
								$figure_tag = '<figure class="' . $figure_classes . '"><img src="' . $new_image_url . '" alt="' . $img_alt . '" class="wp-image-' . $subsite_img_id . '" />'; // phpcs:ignore
							}
							$figure_tag .= $matches[3];

							// Create Tested image block.
							$img_block_arr = array(
								'id' => (int) $subsite_img_id,
							);

							if ( isset( $image_data->width ) ) {
								$img_block_arr['width'] = $image_data->width;
							}
							if ( isset( $image_data->height ) ) {
								$img_block_arr['height'] = $image_data->height;
							}

							$img_block_arr['sizeSlug']        = isset( $image_data->sizeSlug ) ? $image_data->sizeSlug : 'full'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
							$img_block_arr['linkDestination'] = isset( $image_data->linkDestination ) ? $image_data->linkDestination : 'none'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

							if ( isset( $image_data->className ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
								$img_block_arr['className'] = $image_data->className; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
							}

							$block_string = wp_json_encode( $img_block_arr, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );

							return '<!-- wp:image ' . $block_string . ' -->' . $figure_tag . ' <!-- /wp:image -->';
						} else {
							return $matches[0];
						}
					} else {
						return $matches[0];
					}
				}
			},
			$post_content,
			-1
		);

		// Replace non-Gutenberg images.
		$updated_post_content = preg_replace_callback(
			'/<img[^>]+src="([^">]+)"/i',
			function ( $matches ) use ( $subsite_post_id, $content_media, $central_site_url ) {
				$image_url = urldecode( $matches[1] );
				// Check if the image is from the central site.
				if ( strpos( $image_url, $central_site_url ) === false ) {
					return $matches[0]; // Image is already replaced or from another source, skip processing.
				}

				$img_query_p = '';
				$is_query_p  = strpos( $image_url, '?' );

				if ( false !== $is_query_p ) {
					$img_query_p = substr( $image_url, $is_query_p );
				}

				// Get content media id by image url.
				$central_content_media_id = Sync_Process::centralized_content_management_find_media_id_by_url( $image_url, $content_media, 'id' );

				if ( $central_content_media_id ) {
					// Check if image exists based on central attachment ID.
					$existing_image = Sync_Process::centralized_content_management_find_attachment_by_central( $central_content_media_id );

					if ( false !== $existing_image ) {
						$subsite_content_images = $existing_image;
					} else {
						// Get central filepath and image author by image url.
						$central_filepath = Sync_Process::centralized_content_management_find_media_id_by_url( $image_url, $content_media, 'filepath' );
						$image_author     = Sync_Process::centralized_content_management_find_media_id_by_url( $image_url, $content_media, 'author' );

						// Create new attachment in subsite.
						$subsite_content_images = Sync_Process::centralized_content_management_add_attachment_subsite( Utils::centralized_content_management_get_central_site_id(), $central_content_media_id, $central_filepath, $subsite_post_id, $image_author );
					}

					if ( isset( $subsite_content_images['id'] ) && ! empty( $subsite_content_images['id'] ) ) {
						$subsite_img_id = $subsite_content_images['id'];
						$img_sizes      = Sync_Process::centralized_content_management_find_media_id_by_url( $image_url, $content_media, 'sizes' );
						$new_image_url  = wp_get_attachment_image_url( $subsite_img_id, $img_sizes );

						// Append existing querystring in image url.
						$new_image_url .= $img_query_p;

						return str_replace( $matches[1], $new_image_url, $matches[0] );
					}
				}

				return $matches[0];
			},
			$updated_post_content
		);

		if ( ! empty( $updated_post_content ) ) {
			$updated_post_content = addslashes( $updated_post_content );
		}

		// Update post content.
		remove_filter( 'content_save_pre', 'wp_filter_post_kses' );
		remove_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' );
		remove_all_filters( 'content_save_pre' );

		$updated_post = wp_update_post(
			array(
				'ID'           => $subsite_post_id,
				'post_content' => $updated_post_content,
			)
		);

		if ( is_wp_error( $updated_post ) ) {
			// Set error response for post update.
			$response['success']       = false;
			$response['event']         = __( 'Cron Failed', 'centralized-content-management' );
			$response['message']       = __( 'Error updating the post on the subsite.', 'centralized-content-management' );
			$response['debug_message'] = $updated_post->get_error_message();
			$response['sync_status']   = __( 'Failed', 'centralized-content-management' );
		} else {
			// Set success response for post update.
			$response['success']     = true;
			$response['event']       = __( 'Cron Success', 'centralized-content-management' );
			$response['message']     = __( 'Post updated successfully on the subsite.', 'centralized-content-management' );
			$response['sync_status'] = __( 'Success', 'centralized-content-management' );
		}
	}
}

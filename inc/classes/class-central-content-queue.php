<?php
/**
 * Central Content Queue for MD Centralized Content Management plugin.
 *
 * This class manages the central content synchronization queue, storing and processing
 * content updates for synchronization with subsites in a WordPress multisite network.
 * It monitors changes to posts, handles synchronization entries in a custom table,
 * and manages the status of each post's synchronization with selected subsites.
 *
 * @package    Centralized_Content_Management
 * @subpackage Centralized_Content_Management/Inc
 * @since      1.0.0
 */

namespace Centralized_Content_Management\Inc;

use Centralized_Content_Management\Inc\Traits\Singleton;

/**
 * Central Content Queue class.
 */
class Central_Content_Queue {

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

			$this->setup_hooks();
		}
	}

	/**
	 * Set up action hooks for central content synchronization.
	 *
	 * @since 1.0.0
	 */
	public function setup_hooks() {
		add_action( 'save_post', array( $this, 'centralized_content_management_add_to_central_sync' ), 9999, 3 );
		add_action( 'trashed_post', array( $this, 'centralized_content_management_add_trashed_to_central_sync' ), 20, 1 );
	}

	/**
	 * Adds or updates a post in the custom central table for synchronization.
	 *
	 * @param int     $post_id Post ID.
	 * @param object  $post Post Object.
	 * @param boolean $update True if post is being updated, false if new.
	 * @since 1.0.0
	 */
	public function centralized_content_management_add_to_central_sync( $post_id, $post, $update ) {
		// Retrun if auto save
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Return if either post or post ID is empty.
		if ( empty( $post_id ) || empty( $post ) ) {
			return;
		}

		// Check if content queue is enabled.
		if ( ! Utils::is_content_queue_enabled() ) {
			return;
		}

		// Check if the post type is allowed for synchronization.
		if ( ! isset( $post->post_type ) || ! Utils::is_post_type_allowed_for_sync( $post->post_type ) ) {
			return;
		}

		if ( empty( $_POST ) ) { // phpcs:ignore
			return;
		}

		// Check if sync is disabled for this post.
		$sync_disabled    = get_post_meta( $post_id, '_ccm_disable_sync', true );
		$is_sync_disabled = isset( $sync_disabled ) && 'true' === $sync_disabled ? 'true' : 'false';

		if ( 'true' === $is_sync_disabled ) {
			return;
		}

		// Exclude certain post statuses from synchronization.
		$not_allowed_status = array( 'auto-draft', 'pending', 'private', 'trash' );
		if ( true === in_array( $post->post_status, $not_allowed_status, true ) ) {
			return;
		}

		// Save the post to the central sync queue.
		$this->centralized_content_management_save_sync_queue_entry( $post_id, $post, $update );
	}

	/**
	 * Handles trashed posts by adding them to the central sync queue for deletion.
	 *
	 * @param int $central_post_id Central post ID.
	 * @since 1.0.0
	 */
	public function centralized_content_management_add_trashed_to_central_sync( $central_post_id ) {
		// Check if doing autosave to avoid unintended execution during autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check if content queue is enabled.
		if ( ! Utils::is_content_queue_enabled() ) {
			return;
		}

		// Get central settings.
		$centralized_content_management_settings = get_option( 'centralized_content_management_settings' );

		// return if delete_on_subsite is disable.
		if ( isset( $centralized_content_management_settings['delete_on_subsite'] ) && empty( $centralized_content_management_settings['delete_on_subsite'] ) ) {
			return;
		}

		// Retrieve the post from the central site.
		$central_post = get_post( $central_post_id );
		if ( ! $central_post ) {
			return;
		}

		// Add to the sync queue for deletion.
		$this->centralized_content_management_save_sync_queue_entry( $central_post_id, $central_post, true );
	}

	/**
	 * Saves or updates a post's sync entry in the custom central queue table.
	 *
	 * @param int     $post_id Post ID.
	 * @param object  $post Post Object.
	 * @param boolean $update True if the post is updated, false if new.
	 * @since 1.0.0
	 */
	public function centralized_content_management_save_sync_queue_entry( $post_id, $post, $update ) {
		// Check if this post is allowed to be synced for this site.
		$selected_subsites     = get_post_meta( $post_id, '_ccm_selected_subsites', true );
		$bulk_sync_subsite_ids = get_post_meta( $post_id, '_bulk_sync_subsite_ids', true );

		// Expired only if post has synced from bulk synced and local_post_id = 0.
		if ( 'trash' === $post->post_status && ! empty( $bulk_sync_subsite_ids ) ) {
			$selected_subsites             = is_array( $selected_subsites ) ? $selected_subsites : (array) $selected_subsites;
			$keys_not_in_selected_subsites = array_diff_key( (array) $bulk_sync_subsite_ids, array_flip( (array) $selected_subsites ) );

			if ( ! empty( $keys_not_in_selected_subsites ) ) {
				foreach ( $keys_not_in_selected_subsites as $key => $value ) {
					$subsite_prefix      = $this->wpdb->get_blog_prefix( $key );
					$subsite_queue_table = $subsite_prefix . 'ccm_subsite_queue';
					$this->wpdb->query(
						$this->wpdb->prepare( // phpcs:ignore
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							"UPDATE {$subsite_queue_table} SET `sync_status` = 'expired' WHERE `id` = %d AND `sync_status` = 'pending'",
							$value // phpcs:ignore
						)
					);
				}

				return;
			}
		}

		// Return if selected subsite meta is empty.
		if ( ! isset( $selected_subsites ) || empty( $selected_subsites ) ) {
			return;
		}

		// Prepare post data.
		$post_type            = $post->post_type;
		$current_user         = get_current_user_id();
		$subsite_synced_data  = get_post_meta( $post_id, '_synced_subsite_data', true );
		$prepared_post_object = Content_Queue_Helper::centralized_content_management_sync_prepare_post_object( $post_id, $post );
		if ( ! $update ) {
			$sync_type = 'create';
		} else {
			$sync_type = ( isset( $post->post_status ) && 'trash' === $post->post_status ) ? 'delete' : 'update';
		}

		// Set initial sync status for each subsite.
		$sync_status = array();
		if ( ! empty( $selected_subsites ) && is_array( $selected_subsites ) ) {
			foreach ( $selected_subsites as $subsite ) {
				$sync_status[ $subsite ] = 'pending';
			}
		}

		// Prepare data for insertion.
		$data = array(
			'post_id'             => $post_id,
			'post_type'           => $post_type,
			'target_sites'        => maybe_serialize( $selected_subsites ),
			'sync_type'           => $sync_type,
			'sync_status'         => maybe_serialize( $sync_status ),
			'created_time'        => current_time( 'mysql' ),
			'modified_time'       => current_time( 'mysql' ),
			'post_author'         => $current_user,
			'post_object'         => $prepared_post_object['post_object'],
			'post_object_compare' => $prepared_post_object['post_object_compare'],
		);

		// Check if this post is already in custom table or not
		$existing_post = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT `id`, `sync_status` FROM {$this->central_table_name} WHERE `post_id` = %d ORDER BY id DESC", $post_id ), ARRAY_A ); // phpcs:ignore

		if ( ! empty( $existing_post ) && isset( $existing_post['id'] ) ) {
			// Deserialize the existing sync_status
			$sync_status_array = maybe_unserialize( $existing_post['sync_status'] );

			if ( is_array( $sync_status_array ) ) {
				// Mark all subsites' sync statuses as 'expired'
				foreach ( $sync_status_array as $subsite => $status ) {
					$sync_status_array[ $subsite ] = 'expired';
				}

				// Serialize the updated array
				$updated_sync_status = maybe_serialize( $sync_status_array );

				// Update the sync_status and sync_type fields for this specific record
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

		if ( false !== $central_record && ! empty( $selected_subsites ) ) {
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
			foreach ( $selected_subsites as $subsite ) {
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

				$sql_response = $this->wpdb->query( // phpcs:ignore
					$this->wpdb->prepare( // phpcs:ignore
						// phpcs:ignore
						"INSERT INTO {$subsite_queue_table}
					(`central_id`, `central_post_id`, `post_type`, `sync_status`, `sync_type`, `created_time`, `modified_time`, `local_post_id`)
					VALUES ( %d, %d, %s, %s, %s, %s, %s, %d )",
						$post_data // phpcs:ignore
					)
				);
			}
		}
	}
}

<?php
namespace Centralized_Content_Management\Inc;

use Centralized_Content_Management\Inc\Traits\Singleton;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Log_List_Table extends WP_List_Table {

	use Singleton;

	private $log_data;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Log', 'centralized-content-management' ),
				'plural'   => __( 'Logs', 'centralized-content-management' ),
				'ajax'     => false, // We are not using AJAX for this table
			)
		);
	}

	// Define the columns of the table
	public function get_columns() {
		$columns = array(
			'site_id'   => __( 'Site ID', 'centralized-content-management' ),
			'post_id'   => __( 'Post ID', 'centralized-content-management' ),
			'sync_time' => __( 'Sync Time', 'centralized-content-management' ),
			'status'    => __( 'Status', 'centralized-content-management' ),
		);

		return $columns;
	}

	/**
	 * Prepare the items for the table to process.
	 */
	public function prepare_items() {
		$columns     = $this->get_columns();
		$total_items = $this->record_count();
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => 10,
			)
		);
		$this->_column_headers = array( $columns );
		$current_page          = $this->get_pagenum();
		$this->items           = $this->get_data( 10, $current_page );
	}

	/**
	 * Get post data from database.
	 *
	 * @param int $per_page Number of post to show per page.
	 * @param int $page_number Number of page.
	 */
	public function get_data( $per_page = 5, $page_number = 1 ) {
		global $wpdb;

        $table_name         = $wpdb->base_prefix . 'ccm_sync_logs';
        $filter_log_by_site = filter_input( INPUT_GET, 'filter_log_by_site', FILTER_VALIDATE_INT );
        $per_page           = 10;
		$paged              = filter_input( INPUT_GET, 'paged', FILTER_VALIDATE_INT );
        $page_number        = isset( $paged ) ? absint( $paged ) : 1; //phpcs:ignore
        $where_clause       = '';
        //$log_query_params   = [];

        // Check if the log filter by site_id is set and not empty.
        // if ( ! empty( $filter_log_by_site ) ) {
        //     $where_clause = ' WHERE site_id = ' . $filter_log_by_site;
        //     $log_query_params[] = $filter_log_by_site;
        // }

        // $results          = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} {$where_clause} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, ( $page_number - 1 ) * $per_page ), ARRAY_A ); //phpcs:ignore

		$where_clause = '';
		if ( $filter_log_by_site ) {
			$where_clause = $wpdb->prepare( 'WHERE site_id = %d', $filter_log_by_site ); // phpcs:ignore
		}

		$query = $wpdb->prepare( "SELECT * FROM {$table_name} $where_clause ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, ( $page_number - 1 ) * $per_page ); // phpcs:ignore
		$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore


        // $log_query = "
        //     SELECT * FROM $table_name
        //     $where_clause
        //     ORDER BY sync_time DESC
        //     LIMIT %d OFFSET %d
        // ";
        // $log_query_params[] = $per_page;
        // $log_query_params[] = ( $page_number - 1 ) * $per_page;
        // $results = $wpdb->get_results( $wpdb->prepare( $log_query, ...$log_query_params ), ARRAY_A );

		return $results;
	}

    /**
     * Returns the total count of records in the ccm_sync_logs table.
     *
     * @return null|string The total count of records.
     */
    public function record_count() {
        global $wpdb;

        return $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->base_prefix}ccm_sync_logs" ); //phpcs:ignore
    }

	// Populate the row with data for each column
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'site_id':
			case 'post_id':
			case 'sync_time':
			case 'sync_status':
				return $item[ $column_name ];
			default:
				return print_r( $item, true ); //phpcs:ignore
		}
	}

	/**
	 * No posts found.
	 */
	public function no_items() {
		esc_html_e( 'No logs available.', 'centralized-content-management' );
	}
}

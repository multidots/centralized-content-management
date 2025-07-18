<?php
/**
 * Sync Log Manager for the MD Centralized Content Management plugin.
 *
 * This class manages the logging of synchronization events across subsites
 * within the WordPress multisite network. It provides methods for recording
 * and retrieving logs related to post synchronization, including status,
 * timestamps, and error messages, facilitating effective tracking and debugging.
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
class Logs_Manager {

	use Singleton;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->setup_logs_hooks();
	}

	/**
	 * Function is used to define logs hooks.
	 *
	 * @since   1.0.0
	 */
	public function setup_logs_hooks() {
		add_action( 'admin_menu', array( $this, 'centralized_content_management_add_logs_page_to_central_site' ) );

		// VT - 20241112.
		$central_site_id = Utils::get_central_site_id();
		$current_site_id = get_current_blog_id();
		if ( $current_site_id !== $central_site_id ) {
			add_action(
				'admin_menu',
				function() {
					remove_submenu_page( 'md-ccm', 'md-ccm' );
				},
				999
			);
		}
	}

	/**
	 * Function is used to create plugin page to central site.
	 */
	public function centralized_content_management_add_logs_page_to_central_site() {
		$centralized_content_manager_network_settings = Utils::get_network_settings();
		$all_central_sites    = isset( $centralized_content_manager_network_settings['all_central_sites'] ) ? $centralized_content_manager_network_settings['all_central_sites'] : array();
		$central_site_id      = Utils::get_central_site_id(); // VT - 20241112.
		$current_site_id      = get_current_blog_id(); // VT - 20241112.

		if ( $current_site_id == $central_site_id ) {
			add_submenu_page(
				'md-ccm',
				__( 'Sync Logs', 'centralized-content-management' ),
				__( 'Sync Logs', 'centralized-content-management' ),
				'manage_options',
				'md-ccm-sync-logs',
				array( $this, 'centralized_content_management_logs_manager_page_content' )
			);
		} elseif ( $current_site_id !== $central_site_id && in_array( $current_site_id, $all_central_sites, true ) ) { // VT - 20241112.
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

			// Sync logs page.
			add_submenu_page(
				'md-ccm',
				__( 'Sync Logs', 'centralized-content-management' ),
				__( 'Sync Logs', 'centralized-content-management' ),
				'manage_options',
				'md-ccm-sync-logs',
				array( $this, 'centralized_content_management_logs_manager_page_content' )
			);
		}
	}

	/**
	 * Function to render the central setting page content.
	 */
	public function centralized_content_management_logs_manager_page_content() {
		global $wpdb;

		// Retrive filter parameter from query params.
		$log_filter_by_post_title = filter_input( INPUT_GET, 'log_filter_by_post_title', FILTER_SANITIZE_SPECIAL_CHARS );
		$filter_title = isset( $log_filter_by_post_title ) ? $log_filter_by_post_title : ''; // phpcs:ignore

		// Retrive network settings.
		$centralized_content_manager_network_settings = Utils::get_network_settings();
		$current_site_id      = get_current_blog_id();
		$current_central_id   = Utils::get_central_site_id();
		$centralized_content_management_settings = get_option( 'central_setting_data' );
		$is_post_approval     = isset( $centralized_content_management_settings['post_approval'] ) && 1 === (int) $centralized_content_management_settings['post_approval'] ? true : false;
		$sync_subsites        = isset( $centralized_content_manager_network_settings['sync_subsites'] ) ? $centralized_content_manager_network_settings['sync_subsites'] : array();
		if ( isset( $centralized_content_manager_network_settings['all_central_sites'] ) && is_array( $centralized_content_manager_network_settings['all_central_sites'] ) ) {
			if ( ! empty( $centralized_content_manager_network_settings['all_central_sites'] ) && in_array( $current_site_id, $centralized_content_manager_network_settings['all_central_sites'], true ) ) {
				$site_id = get_current_blog_id();
			} else {
				$site_id = Utils::get_central_site_id();
			}
		}

		// Define the table name for the current site using its prefix.
		$table_name = $wpdb->get_blog_prefix( $site_id ) . 'ccm_sync_logs';

		// Build the WHERE clause based on filters.
		$where_clause = array();

		// Filter by post_name.
		if ( $filter_title ) {
			$where_clause[] = $wpdb->prepare(
				'post_name LIKE %s',
				'%' . $wpdb->esc_like( $filter_title ) . '%'
			);
		}

		//$where_sql = ! empty( $where_clause ) ? 'WHERE ' . implode( ' AND ', $where_clause ) : '';
		$where_sql = ! empty( $where_clause ) ? 'WHERE ' . implode( ' AND ', array_map( function( $clause ) use ( $wpdb ) {
			return $wpdb->prepare( "%s", $clause ); // Ensure that each clause is properly escaped
		}, $where_clause ) ) : '';
		

		// Pagination calculations.
		$per_page     = 10;
		$paged        = filter_input( INPUT_GET, 'paged', FILTER_VALIDATE_INT );
		$current_page = isset( $paged ) ? max( 1, intval( $paged ) ) : 1; // phpcs:ignore
		$offset       = ( $current_page - 1 ) * $per_page;

		// Get total count of filtered results for pagination.
		//$total_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name $where_sql" ); // phpcs:ignore
		$query       = $wpdb->prepare( "SELECT COUNT(*) FROM $table_name $where_sql" ); // phpcs:ignore
		$total_count = $wpdb->get_var( $query ); // phpcs:ignore

		$total_pages = ceil( $total_count / $per_page );

		// Fetch the paginated results.
		// $sql     = "SELECT * FROM $table_name $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
		// $results = $wpdb->get_results( $wpdb->prepare( $sql, $per_page, $offset ), ARRAY_A ); // phpcs:ignore
		$sql     = $wpdb->prepare( "SELECT * FROM {$table_name} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ); // phpcs:ignore
		$results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore

		// Determine item text.
		$item_text = ( $total_count === 1 ) ? 'item' : 'items';

		// Preserve filter values in pagination URLs.
		$pagination_base_url = add_query_arg(
			array(
				'page'                     => 'md-ccm-sync-logs',
				'log_filter_by_post_title' => $filter_title,
			),
			admin_url( 'admin.php' )
		);
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
				<p><?php esc_html_e( 'This page provides an overview of the sync process history, showing details about content synchronization between the central and sub sites, including the status and time.', 'centralized-content-management' ); ?></p>
			</div>
			<div class="md-ccm-body">
				<div class="nav-tab-wrapper">
					<?php
					if ( Utils::is_central_site() ) {
						?>
						<div class="nav-tab-item">
							<a href="?page=md-ccm-sync-settings" class="nav-tab"><span><?php esc_html_e( 'Sync Settings', 'centralized-content-management' ); ?></span></a>
						</div>
						<div class="nav-tab-item">
							<a href="?page=md-ccm-bulk-sync" class="nav-tab"><span><?php esc_html_e( 'Bulk Sync', 'centralized-content-management' ); ?></span></a>
						</div>
						<?php
					}
					?>
					<div class="nav-tab-item nav-tab-active">
						<a href="?page=md-ccm-sync-logs" class="nav-tab"><span><?php esc_html_e( 'Sync Logs', 'centralized-content-management' ); ?></span></a>
					</div>
					<?php
					// Check if content queue is enabled or not.
					if ( $current_site_id !== $current_central_id && true === $is_post_approval && in_array( $current_site_id, $sync_subsites, true ) ) {
						?>
						<div class="nav-tab-item">
							<a href="?page=content-queue" class="nav-tab"><span><?php esc_html_e( 'Content Queue', 'centralized-content-management' ); ?></span></a>
						</div>
						<?php
					}
					?>
				</div>
				<div class="md-ccm-body__tab-content" id="md-ccm-sync-log">
					<form method="GET" id="logFilterForm">
						<input type="hidden" name="page" value="md-ccm-sync-logs">
						<div class="logs-filter">
							<div class="logs-filter__fields">
								<input type="text" name="log_filter_by_post_title" id="log_filter_by_post_title" placeholder="Post title..." value="<?php echo esc_attr( $filter_title ); ?>" autocomplete="off" required />
								<button type="submit" class="button button-primary" id="logsFilterButton"><?php esc_html_e( 'Filter', 'centralized-content-management' ); ?></button>
								<?php
								if ( $filter_title ) {
									?>
									<button type="button" class="button button-primary" id="resetLogFilterButton"><?php esc_html_e( 'Reset Filter', 'centralized-content-management' ); ?></button>
									<?php
								}
								?>
							</div>
							<div class="logs-filter__record-count">
								<span class="displaying-num"><?php echo esc_html( $total_count . ' ' . $item_text ); ?></span>
							</div>
						</div>
					</form>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Post', 'centralized-content-management' ); ?></th>
								<th scope="col" class="manage-column"><?php esc_html_e( 'Sites', 'centralized-content-management' ); ?></th>
							</tr>
						</thead>
						<tbody id="logsResults">
							<?php
							if ( ! empty( $results ) ) {
								foreach ( $results as $result ) {
									$post_id    = ( isset( $result['post_id'] ) && ! empty( $result['post_id'] ) ) ? $result['post_id'] : 0;
									$post_name  = ( isset( $result['post_name'] ) && ! empty( $result['post_name'] ) ) ? $result['post_name'] : '';
									$sync_sites = ( isset( $result['sync_sites'] ) && ! empty( $result['sync_sites'] ) ) ? maybe_unserialize( $result['sync_sites'] ) : array(); // phpcs:ignore
									?>
									<tr>
										<td class="column-primary" data-colname="<?php echo esc_attr( $post_name ); ?>">
											<p><?php echo esc_html( $post_name ); ?></p>
											<div class="hidden" id="inline_904">
												<div class="post_name"><?php echo esc_html( $post_name ); ?></div>
												<div class="sync_sites">
													<div class="synced-data">
														<?php
														$total_sites   = count( $sync_sites );
														$visible_sites = 3;
														foreach ( $sync_sites as $index => $sync_site ) {
															$site_id     = ( isset( $sync_site['site_id'] ) && ! empty( $sync_site['site_id'] ) ) ? $sync_site['site_id'] : 0;
															$site_name   = Utils::get_blogname( $site_id );
															$sync_time   = ( isset( $sync_site['sync_time'] ) && ! empty( $sync_site['sync_time'] ) ) ? $sync_site['sync_time'] : '-';
															$sync_status = ( isset( $sync_site['sync_status'] ) && ! empty( $sync_site['sync_status'] ) ) ? $sync_site['sync_status'] : '-';
															?>
																<div class="synced-data__item <?php echo $index >= $visible_sites ? 'synced-data-hidden' : ''; ?>">
																	<span class="synced-data-item__site_name"><?php echo esc_html( $site_name ); ?></span>
																	<span class="synced-data-item__sync_status"><?php echo esc_html( $sync_status ); ?></span>
																	<span class="synced-data-item__sync_time"><?php echo esc_html( $sync_time ); ?></span>
																</div>
																<?php
														}
														?>
													</div>
													<?php if ( $total_sites > $visible_sites ) : ?>
														<div class="show-more-toggle">
															<span>Show More</span>
															<span class="dashicons dashicons-plus-alt"></span>
														</div>
													<?php endif; ?>
												</div>
											</div>
											<button type="button" class="toggle-row"><span class="screen-reader-text">Show more details</span></button>
										</td>
										<td data-colname="Sites" class="column-synced_sites">
											<?php
											$total_sites   = count( $sync_sites );
											$visible_sites = 3;
											?>
											<div class="synced-data <?php echo $total_sites > $visible_sites ? 'sync-data-wrap' : ''; ?>">
												<?php
												foreach ( $sync_sites as $index => $sync_site ) {
													$site_id     = ( isset( $sync_site['site_id'] ) && ! empty( $sync_site['site_id'] ) ) ? $sync_site['site_id'] : 0;
													$site_name   = Utils::get_blogname( $site_id );
													$sync_time   = ( isset( $sync_site['sync_time'] ) && ! empty( $sync_site['sync_time'] ) ) ? $sync_site['sync_time'] : '-';
													$sync_status = ( isset( $sync_site['sync_status'] ) && ! empty( $sync_site['sync_status'] ) ) ? $sync_site['sync_status'] : '-';
													?>
													<div class="synced-data__item <?php echo $index >= $visible_sites ? 'synced-data-hidden' : ''; ?>">
														<span class="synced-data-item__site_name"><?php echo esc_html( $site_name ); ?></span>
														<span class="synced-data-item__sync_status"><?php echo esc_html( $sync_status ); ?></span>
														<span class="synced-data-item__sync_time"><?php echo esc_html( $sync_time ); ?></span>
													</div>
													<?php
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
											?>
										</td>
									</tr>
									<?php
								}
							} else {
								?>
								<tr>
									<td colspan="2" align="center"><?php esc_html_e( 'No data found in Logs.', 'centralized-content-management' ); ?></td>
								</tr>
								<?php
							}
							?>
						</tbody>
					</table>
					<div id="logsPaginationWrap">
						<?php
							// Pagination.
							Utils::centralized_content_management_pagination( $total_pages, $current_page, $pagination_base_url );
						?>
					</div>
				</div>
			</div>
			<?php Utils::centralized_content_management_footer(); ?>
		</div>
		<?php
	}
}

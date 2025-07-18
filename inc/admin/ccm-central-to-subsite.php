<?php
/**
 * Subsite Content Queue.
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

global $wpdb;

$current_site_id     = get_current_blog_id();
$site_prefix         = $wpdb->get_blog_prefix( $current_site_id );
$subsite_queue_table = $site_prefix . 'ccm_subsite_queue';
$central_queue_table = Centralized_Content_Management\Inc\Content_Queue_Helper::centralized_content_management_get_central_queue_table();
$ccm_current_user    = get_current_user_id();

// Pagination calculations.
$items_per_page = 10;
$current_page   = filter_input( INPUT_GET, 'paged', FILTER_VALIDATE_INT );
$current_page   = isset( $current_page ) ? max( 1, $current_page ) : 1;
$offset         = ( $current_page - 1 ) * $items_per_page;

$get_records = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT SQL_CALC_FOUND_ROWS sq.id as subsite_primary_id, sq.*, cq.* FROM {$subsite_queue_table} sq LEFT JOIN {$central_queue_table} cq ON sq.central_id = cq.id WHERE sq.sync_status = 'pending' ORDER BY sq.id ASC LIMIT %d OFFSET %d",
		$items_per_page,
		$offset
	),
	ARRAY_A
);

// Calculate total pages.
$total_items = $wpdb->get_var( 'SELECT FOUND_ROWS();' ); // phpcs:ignore
$total_pages = ! empty( $total_items ) ? ceil( $total_items / $items_per_page ) : 0;

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
		<p><?php esc_html_e( 'Approve, Reject and Preview changes from the Central site before merging them into this site.', 'centralized-content-management' ); ?></p>
	</div>
	<div class="md-ccm-body">
		<div class="md-ccm-body__content-queue-wrap">
			<div class="nav-tab-wrapper">
				<?php
				// Retrive network settings.
				$centralized_content_manager_network_settings = Centralized_Content_Management\Inc\Utils::get_network_settings();
				$current_central_id   = Centralized_Content_Management\Inc\Utils::get_central_site_id();
				$current_site_id      = get_current_blog_id();
				if ( isset( $centralized_content_manager_network_settings['all_central_sites'] ) && is_array( $centralized_content_manager_network_settings['all_central_sites'] ) ) {
					if ( ! empty( $centralized_content_manager_network_settings['all_central_sites'] ) && in_array( $current_site_id, $centralized_content_manager_network_settings['all_central_sites'], true ) && $current_site_id !== $current_central_id ) {
						?>
						<div class="nav-tab-item">
							<a href="?page=md-ccm-sync-logs" class="nav-tab"><span><?php esc_html_e( 'Sync Logs', 'centralized-content-management' ); ?></span></a>
						</div>
						<?php
					}
				}
				?>
				<div class="nav-tab-item nav-tab-active">
					<a href="?page=content-queue" class="nav-tab"><span><?php esc_html_e( 'Content Queue', 'centralized-content-management' ); ?></span></a>
				</div>
			</div>
			<div class="md-ccm-body__tab-content">
				<?php
				if ( ! empty( $get_records ) ) {
					?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Description', 'centralized-content-management' ); ?></th>
								<th><?php esc_html_e( 'Action', 'centralized-content-management' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						foreach ( $get_records as $record ) {
							$post_object = $record['post_object'];

							if ( empty( $post_object ) ) {
								continue;
							}

							$post_type_obj   = get_post_type_object( $record['post_type'] );
							$post_type_label = ( ! empty( $post_type_obj ) ) ? $post_type_obj->labels->singular_name : '';
							$created_time    = wp_date( 'M j, Y H:i', strtotime( $record['created_time'] ) );
							$primary_id      = $record['subsite_primary_id'];
							$central_post_id = $record['central_post_id'];
							$local_post_id   = $record['local_post_id'];
							$post_action     = $record['sync_type'];

							$user_data      = get_userdata( $record['post_author'] );
							$user_full_name = '';
							if ( ! empty( $user_data ) ) {
								$user_full_name = trim( $user_data->first_name . ' ' . $user_data->last_name );
								$user_full_name = ( empty( $user_full_name ) ) ? $user_data->display_name : $user_full_name;
							}

							$post_object_arr     = json_decode( $post_object, true );
							$current_post_status = get_post_status( $local_post_id );
							$post_title          = ( 'auto-draft' === $current_post_status ) ? $post_object_arr['title'] : get_the_title( $local_post_id );
							$post_title          = ( empty( $post_title ) ) ? $post_object_arr['title'] : $post_title;
							$post_title          = ( empty( $post_title ) ) ? '(no title)' : $post_title;
							$updated_status      = ( isset( $post_object_arr['post_status'] ) ) ? $post_object_arr['post_status'] : '';

							$description = '<strong>' . $post_type_label . ' | </strong>';
							if ( 0 === (int) $local_post_id ) {
								$description .= '<span class="md-ccm-theme-color">' . $post_title . '</span>';
							} else {
								$edit_link    = admin_url( 'post.php?action=edit&post=' . $local_post_id );
								$description .= '<a href="' . esc_url( $edit_link ) . '" target="_blank"><span>' . $post_title . '</span></a>';
							}

							if ( 'delete' === $post_action ) {
								$description .= ' deleted';
							} else {
								if ( ! empty( $updated_status ) && 'draft' === $updated_status && 0 === (int) $local_post_id ) {
									$description .= ' added as draft';
								} elseif ( 'draft' === $updated_status && $updated_status !== $current_post_status ) {
									$description .= ' status changed to draft';
								} else {
									$description .= ( ( isset( $post_object_arr['is_auto_draft'] ) && 'yes' === $post_object_arr['is_auto_draft'] ) || 0 === (int) $local_post_id ) ? ' added' : ' changed';
								}
							}

							$description .= ' on <span class="md-ccm-theme-color">' . $created_time . '</span>';
							$description .= ( ! empty( $user_full_name ) ) ? ' by <span class="md-ccm-theme-color">' . $user_full_name . '</span>' : '.';

							?>
							<tr data-primary-id="<?php echo esc_attr( $primary_id ); ?>" data-post-title="<?php echo esc_attr( $post_title ); ?>" data-central-post-id="<?php echo esc_attr( $central_post_id ); ?>" data-local-post-id="<?php echo esc_attr( $local_post_id ); ?>" data-user="<?php echo esc_attr( $record['post_author'] ); ?>" data-post-type="<?php echo esc_attr( $record['post_type'] ); ?>">
								<td><?php echo wp_kses_post( $description ); ?></td>
								<td class="<?php echo ( $ccm_current_user === (int) $record['post_author'] ) ? 'gatekeeper-disabled' : ''; ?>">
									<span class="ccm-approve-request dashicons dashicons-yes-alt" title="Approve Changes"></span>
									<span class="ccm-reject-request dashicons dashicons-dismiss" title="Reject Changes"></span>
									<?php if ( 'delete' === $post_action ) { ?>
										<span class="ccm-preview-request dashicons dashicons-warning" title="View Changes"></span>
									<?php } else { ?>
										<span class="ccm-preview-request dashicons dashicons-visibility" title="Preview Changes"></span>
									<?php } ?>
									<div class="diff-popup-wrap" style="display: none;">
										<div class="diff-popup-content">
										</div>
									</div>
								</td>
							</tr>
							<?php
						}
						?>
						</tbody>
					</table>
					<?php
				} else {
					?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Description', 'centralized-content-management' ); ?></th>
								<th width="25%"><?php esc_html_e( 'Action', 'centralized-content-management' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<tr>
							<td colspan="2"><?php esc_html_e( 'No records found.', 'centralized-content-management' ); ?></td>
						</tr>
						</tbody>
					</table>
					<?php
				}
				?>
			</div>
		</div>
		<div id="logsPaginationWrap">
			<?php
				// Pagination.
				Centralized_Content_Management\Inc\Utils::centralized_content_management_pagination( $total_pages, $current_page );
			?>
		</div>
		<!-- Approval popup -->
		<div class="ccm-popup-model gatekeeper-approve" style="display: none;" data-primary-id="" data-local-post-id="" data-post-type="" data-is-scheduled="" data-is-collection-swap="">
			<div class="ccm-popup-modal-inner">
				<div class="modal-content">
					<i class="ccm-popup-modal-close dashicons dashicons-no-alt"></i>
					<div class="modal-content-wrap">
						<div class="ccm-popup-modal-head">
							<h3><?php esc_html_e( 'Approve Changes', 'centralized-content-management' ); ?></h3>
						</div>
						<div class="ccm-popup-modal-body">
							<p class="ccm-conf-msg"><?php esc_html_e( 'Are you sure you want to approve these changes? This will override existing content.', 'centralized-content-management' ); ?></p>
							<ul class="ccm-approve-action-btn">
								<li><button class="button yes-button" data-btn-action="yes"><?php esc_html_e( 'Yes', 'centralized-content-management' ); ?></button></li>
								<li><button class="button no-button" data-btn-action="no"><?php esc_html_e( 'No', 'centralized-content-management' ); ?></button></li>
							</ul>
							<p class="popup-alert-message" style="display: none;"></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<!-- Reject popup -->
		<div class="ccm-popup-model gatekeeper-reject" style="display: none;" data-primary-id="" data-masthead-post-id="" data-post-title="">
			<div class="ccm-popup-modal-inner">
				<div class="modal-content">
					<i class="ccm-popup-modal-close dashicons dashicons-no-alt"></i>
					<div class="modal-content-wrap">
						<div class="ccm-popup-modal-head">
							<h3><?php esc_html_e( 'Reject Changes', 'centralized-content-management' ); ?></h3>
						</div>
						<div class="ccm-popup-modal-body">
							<form>
								<div class="ccm-model-field-group">
									<label for="message"><?php esc_html_e( 'Please provide a reason to reject these changes.', 'centralized-content-management' ); ?></label>
									<textarea id="message" name="message" class="reject-message" placeholder="Your Message"></textarea>
								</div>
							</form>
							<ul class="reject-action-btn">
								<li><button class="button yes-button" data-btn-action="yes"><?php esc_html_e( 'Submit', 'centralized-content-management' ); ?></button></li>
								<li><button class="button no-button" data-btn-action="no"><?php esc_html_e( 'Cancel', 'centralized-content-management' ); ?></button></li>
							</ul>
							<p class="popup-alert-message" style="display: none;"></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<!-- Preview popup -->
		<div class="ccm-popup-model gatekeeper-preview-changes" style="display: none;" data-primary-id="">
			<div class="ccm-popup-modal-inner">
				<div class="modal-content">
					<i class="ccm-popup-modal-close dashicons dashicons-no-alt"></i>
					<div class="modal-content-wrap">
						<div class="ccm-popup-modal-head">
							<h3><?php esc_html_e( 'Preview Changes', 'centralized-content-management' ); ?></h3>
						</div>
						<div class="ccm-popup-modal-body">
							<div class="ccm-popup-table-wrap"></div>
							<p class="popup-alert-message" style="display: none;"></p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
		// Footer.
		Centralized_Content_Management\Inc\Utils::centralized_content_management_footer();
	?>
</div>

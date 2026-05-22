<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Subscriptions_Admin {

	public function __construct() {
		add_action( 'admin_menu',  [ $this, 'add_menu' ] );
		add_action( 'admin_post_irembopay_subscription_action', [ $this, 'handle_action' ] );
		add_action( 'admin_notices', [ $this, 'admin_notice' ] );
	}

	public function add_menu(): void {
		add_submenu_page( 'woocommerce', __( 'IremboPay Subscriptions', 'wc-irembopay' ), __( 'Subscriptions (IremboPay)', 'wc-irembopay' ), 'manage_woocommerce', 'irembopay-subscriptions', [ $this, 'render_page' ] );
	}

	public function render_page(): void {
		$status   = sanitize_text_field( $_GET['sub_status'] ?? 'any' );
		$page_num = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per_page = 20;
		$subs     = IremboPay_Subscription_DB::get_list( $status, $per_page, ( $page_num - 1 ) * $per_page );
		$total    = IremboPay_Subscription_DB::count( $status );

		$statuses = [ 'any' => __( 'All', 'wc-irembopay' ), 'active' => __( 'Active', 'wc-irembopay' ), 'pending_renewal' => __( 'Pending Renewal', 'wc-irembopay' ), 'paused' => __( 'Paused', 'wc-irembopay' ), 'cancelled' => __( 'Cancelled', 'wc-irembopay' ), 'expired' => __( 'Expired', 'wc-irembopay' ) ];
		$colors   = [ 'active' => '#16a34a', 'pending_renewal' => '#d97706', 'paused' => '#6b7280', 'cancelled' => '#dc2626', 'expired' => '#dc2626' ];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'IremboPay Subscriptions', 'wc-irembopay' ); ?></h1>
			<ul class="subsubsub">
				<?php foreach ( $statuses as $s => $label ) :
					$cnt = IremboPay_Subscription_DB::count( $s === 'any' ? 'any' : $s );
					$url = add_query_arg( [ 'page' => 'irembopay-subscriptions', 'sub_status' => $s ], admin_url( 'admin.php' ) );
				?>
					<li><a href="<?php echo esc_url( $url ); ?>" <?php echo $s === $status ? 'class="current"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="count">(<?php echo $cnt; ?>)</span></a><?php echo $s !== array_key_last( $statuses ) ? ' |' : ''; ?></li>
				<?php endforeach; ?>
			</ul>
			<table class="wp-list-table widefat fixed striped" style="margin-top:10px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'wc-irembopay' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'wc-irembopay' ); ?></th>
						<th><?php esc_html_e( 'Product / Course', 'wc-irembopay' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'wc-irembopay' ); ?></th>
						<th><?php esc_html_e( 'Billing', 'wc-irembopay' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wc-irembopay' ); ?></th>
						<th><?php esc_html_e( 'Next Renewal', 'wc-irembopay' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wc-irembopay' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $subs ) ) : ?>
					<tr><td colspan="8" style="text-align:center;padding:20px;color:#888"><?php esc_html_e( 'No subscriptions found.', 'wc-irembopay' ); ?></td></tr>
				<?php else : foreach ( $subs as $sub ) :
					$user  = get_userdata( $sub->user_id );
					$color = $colors[ $sub->status ] ?? '#888';
				?>
					<tr>
						<td><strong>#<?php echo (int) $sub->id; ?></strong></td>
						<td>
							<?php if ( $user ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a><br>
								<small style="color:#888"><?php echo esc_html( $user->user_email ); ?></small>
							<?php else : ?><em><?php esc_html_e( 'Deleted', 'wc-irembopay' ); ?></em><?php endif; ?>
						</td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $sub->product_id ) ); ?>"><?php echo esc_html( get_the_title( $sub->product_id ) ); ?></a></td>
						<td><?php echo wc_price( $sub->amount, [ 'currency' => $sub->currency ] ); ?></td>
						<td><?php echo esc_html( IremboPay_Subscription_Manager::billing_label( $sub->billing_period, (int) $sub->billing_interval ) ); ?></td>
						<td><span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600"><?php echo esc_html( ucwords( str_replace( '_', ' ', $sub->status ) ) ); ?></span></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $sub->next_renewal ) ) ); ?></td>
						<td><?php $this->render_actions( $sub ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
			<?php
			$total_pages = ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				echo '<div style="margin-top:12px">' . paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page_num, 'total' => $total_pages ] ) . '</div>';
			} ?>
		</div>
		<?php
	}

	private function render_actions( object $sub ): void {
		$nonce = wp_create_nonce( 'irembopay_sub_action_' . $sub->id );
		$base  = admin_url( 'admin-post.php' );
		$btn   = fn( string $do, string $lbl, string $color ) =>
			'<a href="' . esc_url( add_query_arg( [ 'action' => 'irembopay_subscription_action', 'sub_id' => $sub->id, 'do' => $do, '_nonce' => $nonce ], $base ) ) . '" style="margin-right:4px;padding:3px 8px;border-radius:4px;background:' . $color . ';color:#fff;text-decoration:none;font-size:12px">' . esc_html( $lbl ) . '</a>';

		switch ( $sub->status ) {
			case 'active':
				echo $btn( 'pause',      __( 'Pause',      'wc-irembopay' ), '#6b7280' );
				echo $btn( 'cancel',     __( 'Cancel',     'wc-irembopay' ), '#dc2626' );
				echo $btn( 'renew_now',  __( 'Renew Now',  'wc-irembopay' ), '#2563eb' );
				break;
			case 'paused':
				echo $btn( 'reactivate', __( 'Reactivate', 'wc-irembopay' ), '#16a34a' );
				echo $btn( 'cancel',     __( 'Cancel',     'wc-irembopay' ), '#dc2626' );
				break;
			case 'cancelled': case 'expired':
				echo $btn( 'reactivate', __( 'Reactivate', 'wc-irembopay' ), '#16a34a' );
				break;
		}
		echo '<a href="' . esc_url( get_edit_post_link( $sub->parent_order_id ) ) . '" style="font-size:12px;color:#888;margin-left:4px">' . esc_html__( 'Order', 'wc-irembopay' ) . ' #' . (int) $sub->parent_order_id . '</a>';
	}

	public function handle_action(): void {
		$sub_id = absint( $_GET['sub_id'] ?? 0 );
		$do     = sanitize_key( $_GET['do'] ?? '' );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( sanitize_text_field( $_GET['_nonce'] ?? '' ), 'irembopay_sub_action_' . $sub_id ) ) {
			wp_die( 'Security check failed.' );
		}
		$sub = IremboPay_Subscription_DB::get( $sub_id );
		if ( ! $sub ) { wp_die( 'Subscription not found.' ); }

		$msg = match ( $do ) {
			'cancel'      => ( IremboPay_Subscription_Manager::cancel( $sub_id, 'Manual admin action' ),  'cancelled' ),
			'pause'       => ( IremboPay_Subscription_Manager::pause( $sub_id ),                          'paused' ),
			'reactivate'  => ( IremboPay_Subscription_Manager::reactivate( $sub_id ),                     'reactivated' ),
			'renew_now'   => ( IremboPay_Subscription_Manager::trigger_renewal( $sub ),                   'renewal_triggered' ),
			default       => 'unknown',
		};

		wp_redirect( add_query_arg( [ 'page' => 'irembopay-subscriptions', 'sub_message' => $msg ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function admin_notice(): void {
		if ( ! isset( $_GET['sub_message'] ) ) { return; }
		$msgs = [
			'cancelled'         => __( 'Subscription cancelled successfully.', 'wc-irembopay' ),
			'paused'            => __( 'Subscription paused.', 'wc-irembopay' ),
			'reactivated'       => __( 'Subscription reactivated.', 'wc-irembopay' ),
			'renewal_triggered' => __( 'Renewal invoice created and email sent to customer.', 'wc-irembopay' ),
		];
		$key = sanitize_key( $_GET['sub_message'] );
		if ( isset( $msgs[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msgs[ $key ] ) . '</p></div>';
		}
	}
}

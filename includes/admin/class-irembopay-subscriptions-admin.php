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
						<th style="width:100px"><?php esc_html_e( 'Next Renewal', 'wc-irembopay' ); ?></th>
						<th style="width:160px"><?php esc_html_e( 'Parent WhatsApp', 'wc-irembopay' ); ?></th>
						<th style="width:110px"><?php esc_html_e( 'Actions', 'wc-irembopay' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $subs ) ) : ?>
					<tr><td colspan="9" style="text-align:center;padding:20px;color:#888"><?php esc_html_e( 'No subscriptions found.', 'wc-irembopay' ); ?></td></tr>
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
						<td><?php $this->render_whatsapp_field( $sub ); ?></td>
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

		$btn = fn( string $do, string $lbl, string $bg ) =>
			'<a href="' . esc_url( add_query_arg( [
				'action' => 'irembopay_subscription_action',
				'sub_id' => $sub->id,
				'do'     => $do,
				'_nonce' => $nonce,
			], $base ) ) . '"
			style="display:block;text-align:center;margin-bottom:4px;padding:4px 10px;
			       border-radius:5px;background:' . $bg . ';color:#fff;
			       text-decoration:none;font-size:11px;font-weight:600;white-space:nowrap;">
				' . esc_html( $lbl ) . '
			</a>';

		echo '<div style="min-width:90px;">';

		switch ( $sub->status ) {
			case 'active':
				echo $btn( 'pause',     __( '⏸ Pause',      'wc-irembopay' ), '#6b7280' );
				echo $btn( 'cancel',    __( '✖ Cancel',     'wc-irembopay' ), '#dc2626' );
				echo $btn( 'renew_now', __( '↻ Renew Now',  'wc-irembopay' ), '#2563eb' );
				break;
			case 'paused':
				echo $btn( 'reactivate', __( '▶ Reactivate', 'wc-irembopay' ), '#16a34a' );
				echo $btn( 'cancel',     __( '✖ Cancel',     'wc-irembopay' ), '#dc2626' );
				break;
			case 'cancelled':
			case 'expired':
				echo $btn( 'reactivate', __( '▶ Reactivate', 'wc-irembopay' ), '#16a34a' );
				break;
		}

		echo '<a href="' . esc_url( get_edit_post_link( $sub->parent_order_id ) ) . '"
			style="display:block;text-align:center;font-size:10px;color:#9ca3af;
			       text-decoration:none;margin-top:4px;">
			🧾 ' . esc_html__( 'Order', 'wc-irembopay' ) . ' #' . (int) $sub->parent_order_id . '
		</a>';

		echo '</div>';
	}

	public function handle_action(): void {
		$sub_id = absint( $_GET['sub_id'] ?? 0 );
		$do     = sanitize_key( $_GET['do'] ?? '' );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( sanitize_text_field( $_GET['_nonce'] ?? '' ), 'irembopay_sub_action_' . $sub_id ) ) {
			wp_die( 'Security check failed.' );
		}
		$sub = IremboPay_Subscription_DB::get( $sub_id );
		if ( ! $sub ) { wp_die( 'Subscription not found.' ); }

		$msg = 'unknown';
		switch ( $do ) {
			case 'cancel':
				IremboPay_Subscription_Manager::cancel( $sub_id, 'Manual admin action' );
				$msg = 'cancelled';
				break;
			case 'pause':
				IremboPay_Subscription_Manager::pause( $sub_id );
				$msg = 'paused';
				break;
			case 'reactivate':
				IremboPay_Subscription_Manager::reactivate( $sub_id );
				$msg = 'reactivated';
				break;
			case 'renew_now':
				IremboPay_Subscription_Manager::trigger_renewal( $sub );
				$msg = 'renewal_triggered';
				break;
		}

		wp_redirect( add_query_arg( [ 'page' => 'irembopay-subscriptions', 'sub_message' => $msg ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private function render_whatsapp_field( object $sub ): void {
		$user = get_userdata( $sub->user_id );

		// Deleted user
		if ( ! $user ) {
			echo '<span style="color:#ccc;font-size:13px;">—</span>';
			return;
		}

		$parent_phone = sanitize_text_field(
			get_user_meta( $sub->user_id, 'phone_number', true ) ?: ( $sub->parent_whatsapp ?? '' )
		);

		// No phone — show warning + edit profile link
		if ( empty( $parent_phone ) ) {
			$edit_url = get_edit_user_link( $sub->user_id );
			echo '<div style="line-height:1.6;">';
			echo '<span style="color:#f59e0b;font-size:12px;font-weight:600;">⚠️ No parent info</span><br>';
			echo '<a href="' . esc_url( $edit_url ) . '" style="font-size:11px;color:#2563eb;text-decoration:none;">✏️ Edit Profile</a>';
			echo '</div>';
			return;
		}

		$parent_name   = sanitize_text_field( get_user_meta( $sub->user_id, 'parent_name', true ) ?: __( 'Parent/Guardian', 'wc-irembopay' ) );
		$student_name  = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
		$student_first = trim( explode( ' ', $student_name )[0] );
		$clean_course  = html_entity_decode( get_the_title( $sub->product_id ) ?: 'course subscription', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$site_name     = get_bloginfo( 'name' );
		$amount_text   = number_format( (float) $sub->amount, 0, '.', ',' ) . ' ' . $sub->currency;
		$grace_days    = (int) $sub->grace_period_days;

		// Build message
		if ( ! empty( $sub->last_invoice ) && ! empty( $sub->renewal_order_id ) ) {
			$renewal_order = wc_get_order( (int) $sub->renewal_order_id );
			if ( $renewal_order ) {
				$pay_url = add_query_arg( [
					'irembopay_payment' => '1',
					'order_id'          => $renewal_order->get_id(),
					'invoice_number'    => rawurlencode( $sub->last_invoice ),
					'key'               => $renewal_order->get_order_key(),
				], home_url( '/' ) );
				$message = sprintf(
					"Hello %s! 👋\n\n⚠️ Your child *%s*'s access to *%s* on *%s* expires soon!\n\n💳 Amount due: %s\n⏳ Only %d days left — after that access is suspended automatically.\n\n👉 Pay Now: %s\n\nThank you! 🙏",
					$parent_name, $student_first, $clean_course, $site_name, $amount_text, $grace_days, $pay_url
				);
			} else {
				$message = sprintf(
					"Hello %s! 👋\n\nThis is a reminder about *%s*'s subscription to *%s* on *%s*.\n\n💳 Amount: %s per billing cycle.\n\nPlease contact us if you have any questions. Thank you! 🙏",
					$parent_name, $student_first, $clean_course, $site_name, $amount_text
				);
			}
		} else {
			$message = sprintf(
				"Hello %s! 👋\n\nThis is a reminder about *%s*'s subscription to *%s* on *%s*.\n\n💳 Amount: %s per billing cycle.\n\nPlease contact us if you have any questions. Thank you! 🙏",
				$parent_name, $student_first, $clean_course, $site_name, $amount_text
			);
		}

		// Build WhatsApp link with lowercase %0a (esc_attr not esc_url to preserve case)
		$digits = preg_replace( '/\D/', '', $parent_phone );
		if ( strlen( $digits ) === 10 && str_starts_with( $digits, '0' ) ) {
			$digits = '250' . ltrim( $digits, '0' );
		}
		$lines   = explode( "\n", $message );
		$encoded = implode( '%0a', array_map( 'urlencode', $lines ) );
		$wa_link = 'https://web.whatsapp.com/send?phone=' . $digits . '&text=' . $encoded;

		echo '<div style="line-height:1.8;">';
		echo '<span style="font-size:12px;font-weight:600;color:#1a1a1a;">📱 ' . esc_html( $parent_phone ) . '</span><br>';
		echo '<a href="' . esc_attr( $wa_link ) . '" target="_blank"
			style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;
			       color:#fff;background:#25d366;padding:3px 10px;border-radius:12px;
			       text-decoration:none;margin-top:2px;">
			💬 ' . esc_html__( 'Send WhatsApp', 'wc-irembopay' ) . '
		</a>';
		echo '</div>';
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

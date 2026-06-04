<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class IremboPay_Subscription_List_Table extends WP_List_Table {

	private string $status_filter;

	public function __construct( string $status_filter = 'any' ) {
		parent::__construct( [
			'singular' => 'subscription',
			'plural'   => 'subscriptions',
			'ajax'     => false,
		] );
		$this->status_filter = $status_filter;
	}

	public function get_columns(): array {
		return [
			'cb'              => '<input type="checkbox">',
			'customer'        => __( 'Customer', 'wc-irembopay' ),
			'product'         => __( 'Product / Course', 'wc-irembopay' ),
			'amount'          => __( 'Amount', 'wc-irembopay' ),
			'billing'         => __( 'Billing', 'wc-irembopay' ),
			'status'          => __( 'Status', 'wc-irembopay' ),
			'next_renewal'    => __( 'Next Renewal', 'wc-irembopay' ),
			'parent_whatsapp' => __( 'Parent WhatsApp', 'wc-irembopay' ),
			'actions'         => __( 'Actions', 'wc-irembopay' ),
		];
	}

	public function get_bulk_actions(): array {
		return [
			'bulk_pause'  => __( '⏸ Pause', 'wc-irembopay' ),
			'bulk_cancel' => __( '✖ Cancel', 'wc-irembopay' ),
			'bulk_delete' => __( '🗑 Delete', 'wc-irembopay' ),
		];
	}

	protected function get_sortable_columns(): array {
		return [
			'next_renewal' => [ 'next_renewal', false ],
			'status'       => [ 'status', false ],
			'amount'       => [ 'amount', false ],
		];
	}

	public function prepare_items(): void {
		$per_page     = $this->get_items_per_page( 'irembopay_subs_per_page', 25 );
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;
		$search       = sanitize_text_field( $_REQUEST['s'] ?? '' );

		if ( ! empty( $search ) ) {
			$items = IremboPay_Subscription_DB::search( $search, $this->status_filter, $per_page, $offset );
			$total = IremboPay_Subscription_DB::search_count( $search, $this->status_filter );
		} else {
			$items = IremboPay_Subscription_DB::get_list( $this->status_filter, $per_page, $offset );
			$total = IremboPay_Subscription_DB::count( $this->status_filter );
		}

		$this->items = $items;
		$this->set_pagination_args( [
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total / $per_page ),
		] );

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
	}

	protected function column_cb( $item ): string {
		return '<input type="checkbox" name="subscription_ids[]" value="' . (int) $item->id . '">';
	}

	protected function column_customer( $item ): string {
		$user = get_userdata( $item->user_id );
		if ( ! $user ) { return '<em style="color:#aaa">' . __( 'Deleted', 'wc-irembopay' ) . '</em>'; }
		return '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '" style="font-weight:600">'
			. esc_html( $user->display_name ) . '</a><br>'
			. '<small style="color:#888">' . esc_html( $user->user_email ) . '</small>';
	}

	protected function column_product( $item ): string {
		return '<a href="' . esc_url( get_edit_post_link( $item->product_id ) ) . '">'
			. esc_html( get_the_title( $item->product_id ) ) . '</a>';
	}

	protected function column_amount( $item ): string {
		return wc_price( $item->amount, [ 'currency' => $item->currency ] );
	}

	protected function column_billing( $item ): string {
		return esc_html( IremboPay_Subscription_Manager::billing_label( $item->billing_period, (int) $item->billing_interval ) );
	}

	protected function column_status( $item ): string {
		$colors = [
			'active'          => '#16a34a',
			'pending_renewal' => '#d97706',
			'paused'          => '#6b7280',
			'cancelled'       => '#dc2626',
			'expired'         => '#dc2626',
		];
		$color = $colors[ $item->status ] ?? '#888';
		return '<span style="color:' . esc_attr( $color ) . ';font-weight:700">'
			. esc_html( ucwords( str_replace( '_', ' ', $item->status ) ) ) . '</span>';
	}

	protected function column_next_renewal( $item ): string {
		return esc_html( date_i18n( get_option( 'date_format' ), strtotime( $item->next_renewal ) ) );
	}

	protected function column_parent_whatsapp( $item ): string {
		$user = get_userdata( $item->user_id );
		if ( ! $user ) { return '<span style="color:#ccc">—</span>'; }

		$parent_phone = sanitize_text_field(
			get_user_meta( $item->user_id, 'phone_number', true ) ?: ( $item->parent_whatsapp ?? '' )
		);

		if ( empty( $parent_phone ) ) {
			return '<span style="color:#f59e0b;font-size:12px;font-weight:600">⚠️ No parent info</span><br>'
				. '<a href="' . esc_url( get_edit_user_link( $item->user_id ) ) . '" style="font-size:11px;color:#2563eb;text-decoration:none">✏️ Edit Profile</a>';
		}

		$parent_name   = sanitize_text_field( get_user_meta( $item->user_id, 'parent_name', true ) ?: 'Parent/Guardian' );
		$student_first = trim( explode( ' ', trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name )[0] );
		$clean_course  = html_entity_decode( get_the_title( $item->product_id ) ?: 'course subscription', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$site_name     = get_bloginfo( 'name' );
		$amount_text   = number_format( (float) $item->amount, 0, '.', ',' ) . ' ' . $item->currency;
		$grace_days    = (int) $item->grace_period_days;

		if ( ! empty( $item->last_invoice ) && ! empty( $item->renewal_order_id ) ) {
			$renewal_order = wc_get_order( (int) $item->renewal_order_id );
			if ( $renewal_order ) {
				$pay_url = add_query_arg( [
					'irembopay_payment' => '1',
					'order_id'          => $renewal_order->get_id(),
					'invoice_number'    => rawurlencode( $item->last_invoice ),
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

		$digits = preg_replace( '/\D/', '', $parent_phone );
		if ( strlen( $digits ) === 10 && str_starts_with( $digits, '0' ) ) {
			$digits = '250' . ltrim( $digits, '0' );
		}
		$encoded = implode( '%0a', array_map( 'urlencode', explode( "\n", $message ) ) );
		$wa_link = 'https://web.whatsapp.com/send?phone=' . $digits . '&text=' . $encoded;

		return '<span style="font-size:12px;font-weight:600;color:#1a1a1a">📱 ' . esc_html( $parent_phone ) . '</span><br>'
			. '<a href="' . esc_attr( $wa_link ) . '" target="_blank"
				style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;
				       color:#fff;background:#25d366;padding:3px 10px;border-radius:12px;
				       text-decoration:none;margin-top:3px;">
				💬 ' . esc_html__( 'Send WhatsApp', 'wc-irembopay' ) . '
			</a>';
	}

	protected function column_actions( $item ): string {
		$nonce = wp_create_nonce( 'irembopay_sub_action_' . $item->id );
		$base  = admin_url( 'admin-post.php' );

		$btn = fn( string $do, string $lbl, string $bg ) =>
			'<a href="' . esc_url( add_query_arg( [
				'action' => 'irembopay_subscription_action',
				'sub_id' => $item->id,
				'do'     => $do,
				'_nonce' => $nonce,
			], $base ) ) . '"
			style="display:block;text-align:center;margin-bottom:4px;padding:4px 10px;
			       border-radius:5px;background:' . $bg . ';color:#fff;
			       text-decoration:none;font-size:11px;font-weight:600;white-space:nowrap;">
				' . esc_html( $lbl ) . '
			</a>';

		$out = '<div style="min-width:100px;">';
		switch ( $item->status ) {
			case 'active':
				$out .= $btn( 'pause',     '⏸ Pause',     '#6b7280' );
				$out .= $btn( 'cancel',    '✖ Cancel',    '#dc2626' );
				$out .= $btn( 'renew_now', '↻ Renew Now', '#2563eb' );
				break;
			case 'paused':
				$out .= $btn( 'reactivate', '▶ Reactivate', '#16a34a' );
				$out .= $btn( 'cancel',     '✖ Cancel',     '#dc2626' );
				break;
			case 'cancelled':
			case 'expired':
				$out .= $btn( 'reactivate', '▶ Reactivate', '#16a34a' );
				break;
		}
		$out .= '<a href="' . esc_url( get_edit_post_link( $item->parent_order_id ) ) . '"
			style="display:block;text-align:center;font-size:10px;color:#9ca3af;text-decoration:none;margin-top:4px;">
			🧾 Order #' . (int) $item->parent_order_id . '
		</a>';
		$out .= '</div>';
		return $out;
	}

	protected function column_default( $item, $column_name ): string {
		return '';
	}
}


class IremboPay_Subscriptions_Admin {

	public function __construct() {
		add_action( 'admin_menu',  [ $this, 'add_menu' ] );
		add_action( 'admin_post_irembopay_subscription_action', [ $this, 'handle_action' ] );
		add_action( 'admin_post_irembopay_bulk_action',         [ $this, 'handle_bulk_action' ] );
		add_action( 'admin_notices', [ $this, 'admin_notice' ] );
		add_filter( 'set_screen_option_irembopay_subs_per_page', [ $this, 'save_screen_option' ], 10, 3 );
	}

	public function add_menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'IremboPay Subscriptions', 'wc-irembopay' ),
			__( 'Subscriptions (IremboPay)', 'wc-irembopay' ),
			'manage_woocommerce',
			'irembopay-subscriptions',
			[ $this, 'render_page' ]
		);
		add_action( "load-{$hook}", [ $this, 'screen_options' ] );
	}

	public function screen_options(): void {
		add_screen_option( 'per_page', [
			'label'   => __( 'Subscriptions per page', 'wc-irembopay' ),
			'default' => 25,
			'option'  => 'irembopay_subs_per_page',
		] );
	}

	public function save_screen_option( $status, string $option, $value ): int {
		return (int) $value;
	}

	public function render_page(): void {
		$status   = sanitize_text_field( $_GET['sub_status'] ?? 'any' );
		$statuses = [
			'any'             => __( 'All', 'wc-irembopay' ),
			'active'          => __( 'Active', 'wc-irembopay' ),
			'pending_renewal' => __( 'Pending Renewal', 'wc-irembopay' ),
			'paused'          => __( 'Paused', 'wc-irembopay' ),
			'cancelled'       => __( 'Cancelled', 'wc-irembopay' ),
			'expired'         => __( 'Expired', 'wc-irembopay' ),
		];

		$list_table = new IremboPay_Subscription_List_Table( $status );
		$list_table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'IremboPay Subscriptions', 'wc-irembopay' ); ?></h1>
			<hr class="wp-header-end">

			<ul class="subsubsub">
				<?php foreach ( $statuses as $s => $label ) :
					$cnt = IremboPay_Subscription_DB::count( $s === 'any' ? 'any' : $s );
					$url = add_query_arg( [ 'page' => 'irembopay-subscriptions', 'sub_status' => $s ], admin_url( 'admin.php' ) );
				?>
					<li>
						<a href="<?php echo esc_url( $url ); ?>" <?php echo $s === $status ? 'class="current" aria-current="page"' : ''; ?>>
							<?php echo esc_html( $label ); ?>
							<span class="count">(<?php echo (int) $cnt; ?>)</span>
						</a>
						<?php echo $s !== array_key_last( $statuses ) ? ' | ' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="get">
				<input type="hidden" name="page" value="irembopay-subscriptions">
				<input type="hidden" name="sub_status" value="<?php echo esc_attr( $status ); ?>">
				<?php $list_table->search_box( __( 'Search subscriptions', 'wc-irembopay' ), 'subscription' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="irembopay_bulk_action">
				<input type="hidden" name="sub_status" value="<?php echo esc_attr( $status ); ?>">
				<?php
				wp_nonce_field( 'irembopay_bulk_action', '_bulk_nonce' );
				$list_table->display();
				?>
			</form>
		</div>
		<?php
	}

	public function handle_bulk_action(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ||
		     ! wp_verify_nonce( sanitize_text_field( $_POST['_bulk_nonce'] ?? '' ), 'irembopay_bulk_action' ) ) {
			wp_die( 'Security check failed.' );
		}

		$bulk_action = sanitize_key( $_POST['action'] ?? $_POST['action2'] ?? '' );
		$ids         = array_map( 'absint', $_POST['subscription_ids'] ?? [] );

		if ( empty( $ids ) ) {
			wp_redirect( add_query_arg( [ 'page' => 'irembopay-subscriptions', 'sub_message' => 'no_selection' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		global $wpdb;
		$count = 0;

		foreach ( $ids as $id ) {
			switch ( $bulk_action ) {
				case 'bulk_pause':
					IremboPay_Subscription_Manager::pause( $id );
					$count++;
					break;
				case 'bulk_cancel':
					IremboPay_Subscription_Manager::cancel( $id, 'Bulk admin action' );
					$count++;
					break;
				case 'bulk_delete':
					$wpdb->delete( IremboPay_Subscription_DB::table(), [ 'id' => $id ] );
					$count++;
					break;
			}
		}

		$msg = match( $bulk_action ) {
			'bulk_pause'  => 'bulk_paused',
			'bulk_cancel' => 'bulk_cancelled',
			'bulk_delete' => 'bulk_deleted',
			default       => 'unknown',
		};

		wp_redirect( add_query_arg( [
			'page'        => 'irembopay-subscriptions',
			'sub_status'  => sanitize_key( $_POST['sub_status'] ?? 'any' ),
			'sub_message' => $msg,
			'count'       => $count,
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_action(): void {
		$sub_id = absint( $_GET['sub_id'] ?? 0 );
		$do     = sanitize_key( $_GET['do'] ?? '' );
		if ( ! current_user_can( 'manage_woocommerce' ) ||
		     ! wp_verify_nonce( sanitize_text_field( $_GET['_nonce'] ?? '' ), 'irembopay_sub_action_' . $sub_id ) ) {
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

	public function admin_notice(): void {
		if ( ! isset( $_GET['sub_message'] ) ) { return; }
		$count = (int) ( $_GET['count'] ?? 0 );
		$msgs  = [
			'cancelled'         => __( 'Subscription cancelled.', 'wc-irembopay' ),
			'paused'            => __( 'Subscription paused.', 'wc-irembopay' ),
			'reactivated'       => __( 'Subscription reactivated.', 'wc-irembopay' ),
			'renewal_triggered' => __( 'Renewal invoice created and email sent.', 'wc-irembopay' ),
			'bulk_paused'       => sprintf( __( '%d subscription(s) paused.', 'wc-irembopay' ), $count ),
			'bulk_cancelled'    => sprintf( __( '%d subscription(s) cancelled.', 'wc-irembopay' ), $count ),
			'bulk_deleted'      => sprintf( __( '%d subscription(s) permanently deleted.', 'wc-irembopay' ), $count ),
			'no_selection'      => __( 'No subscriptions selected.', 'wc-irembopay' ),
		];
		$key = sanitize_key( $_GET['sub_message'] );
		if ( isset( $msgs[ $key ] ) ) {
			$type = str_contains( $key, 'delete' ) ? 'warning' : 'success';
			echo '<div class="notice notice-' . $type . ' is-dismissible"><p>' . esc_html( $msgs[ $key ] ) . '</p></div>';
		}
	}
}

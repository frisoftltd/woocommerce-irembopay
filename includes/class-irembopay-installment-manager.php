<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class IremboPay_Installment_Manager {

	// ------------------------------------------------------------------ //
	//  Called from cron: create invoices for installments now due
	// ------------------------------------------------------------------ //

	public static function process_due_installments(): void {
		$due = IremboPay_Installment_DB::get_due_pending();
		IremboPay_Logger::info( 'Installment cron: processing ' . count( $due ) . ' due installment(s).' );
		foreach ( $due as $inst ) {
			self::bill_installment( $inst );
		}
	}

	// ------------------------------------------------------------------ //
	//  Called from cron: send reminders 1 day before due
	// ------------------------------------------------------------------ //

	public static function process_upcoming_reminders(): void {
		$upcoming = IremboPay_Installment_DB::get_upcoming_pending( 1 );
		foreach ( $upcoming as $inst ) {
			$sub = IremboPay_Subscription_DB::get( (int) $inst->subscription_id );
			if ( ! $sub ) { continue; }
			$user = get_userdata( (int) $sub->user_id );
			if ( ! $user ) { continue; }
			self::send_email_reminder( $user, $sub, $inst );
		}
	}

	// ------------------------------------------------------------------ //
	//  Called from cron: handle overdue installments (grace + revocation)
	// ------------------------------------------------------------------ //

	public static function process_overdue_installments(): void {
		$overdue = IremboPay_Installment_DB::get_all_overdue();
		foreach ( $overdue as $inst ) {
			$sub = IremboPay_Subscription_DB::get( (int) $inst->subscription_id );
			if ( ! $sub ) { continue; }

			$grace_days  = (int) $sub->grace_period_days;
			$days_overdue = (int) floor( ( time() - strtotime( $inst->due_date ) ) / DAY_IN_SECONDS );

			$user = get_userdata( (int) $sub->user_id );
			if ( ! $user ) { continue; }

			if ( $days_overdue <= $grace_days ) {
				self::send_email_overdue_notice( $user, $sub, $inst, $grace_days - $days_overdue );
			} else {
				self::suspend_access( $sub, $inst, $user );
			}
		}
	}

	// ------------------------------------------------------------------ //
	//  Create installment rows 2..N after first payment
	// ------------------------------------------------------------------ //

	public static function create_installments_for_order( WC_Order $order, int $sub_id ): void {
		$chosen_n      = (int) $order->get_meta( '_irembopay_chosen_installments' );
		$inst_amount   = (float) $order->get_meta( '_irembopay_installment_amount' );
		$full_amount   = (float) $order->get_meta( '_irembopay_installment_full_amount' );

		if ( $chosen_n <= 1 || $inst_amount <= 0 ) { return; }

		$sub = IremboPay_Subscription_DB::get( $sub_id );
		if ( ! $sub ) { return; }

		$cycle_days = self::billing_cycle_days( $sub->billing_period, (int) $sub->billing_interval );
		$now        = current_time( 'timestamp' );

		for ( $n = 2; $n <= $chosen_n; $n++ ) {
			$due_ts  = $now + (int) round( $cycle_days / $chosen_n * $n * DAY_IN_SECONDS );
			$due_str = date( 'Y-m-d H:i:s', $due_ts );

			IremboPay_Installment_DB::insert( [
				'subscription_id'    => $sub_id,
				'order_id'           => $order->get_id(),
				'installment_no'     => $n,
				'total_installments' => $chosen_n,
				'amount'             => $inst_amount,
				'currency'           => $order->get_currency(),
				'due_date'           => $due_str,
				'status'             => 'pending',
			] );
		}

		IremboPay_Logger::info( "Created " . ( $chosen_n - 1 ) . " installment row(s) for subscription #{$sub_id}." );
	}

	// ------------------------------------------------------------------ //
	//  Called from webhook when an installment invoice is paid
	// ------------------------------------------------------------------ //

	public static function complete_installment_payment( object $inst ): void {
		$now = current_time( 'mysql' );
		IremboPay_Installment_DB::update( (int) $inst->id, [
			'status'    => 'paid',
			'paid_date' => $now,
		] );

		$sub = IremboPay_Subscription_DB::get( (int) $inst->subscription_id );
		if ( ! $sub ) { return; }

		// Restore access if subscription was suspended due to this installment
		$suspended_at = IremboPay_Subscription_DB::get( (int) $inst->subscription_id )
			? get_option( "irembopay_inst_suspended_{$inst->subscription_id}" )
			: null;

		if ( $suspended_at ) {
			$suspension_days = (int) floor( ( time() - strtotime( $suspended_at ) ) / DAY_IN_SECONDS );
			if ( $suspension_days > 0 && ! empty( $sub->end_date ) ) {
				$new_end = date( 'Y-m-d H:i:s', strtotime( $sub->end_date ) + $suspension_days * DAY_IN_SECONDS );
				IremboPay_Subscription_DB::update( (int) $sub->id, [ 'end_date' => $new_end ] );
			}
			delete_option( "irembopay_inst_suspended_{$inst->subscription_id}" );

			$user = get_userdata( (int) $sub->user_id );
			do_action( 'irembopay_installment_access_restored', (int) $sub->id, null );
			if ( $user ) { self::send_email_access_restored( $user, $sub, $inst ); }

			IremboPay_Logger::info( "Installment #{$inst->id}: access restored for sub #{$sub->id}." );
		}

		// Check if all installments are now paid
		if ( IremboPay_Installment_DB::count_remaining( (int) $sub->id ) === 0 ) {
			IremboPay_Logger::info( "All installments paid for subscription #{$sub->id}." );
		}

		IremboPay_Logger::info( "Installment #{$inst->id} (sub #{$sub->id}, no {$inst->installment_no}/{$inst->total_installments}) marked paid." );
	}

	// ------------------------------------------------------------------ //
	//  Internal: create invoice for a single due installment
	// ------------------------------------------------------------------ //

	private static function bill_installment( object $inst ): void {
		$sub = IremboPay_Subscription_DB::get( (int) $inst->subscription_id );
		if ( ! $sub ) { return; }

		$settings           = get_option( 'woocommerce_irembopay_settings', [] );
		$secret_key         = $settings['secret_key']         ?? '';
		$payment_identifier = $settings['payment_identifier'] ?? '';
		if ( empty( $secret_key ) ) {
			IremboPay_Logger::error( "Installment #{$inst->id}: skipped — secret key not configured." );
			return;
		}

		$user = get_userdata( (int) $sub->user_id );
		if ( ! $user ) { return; }

		$product_code  = get_post_meta( $sub->product_id, '_irembopay_product_code', true ) ?: ( $settings['product_code'] ?? '' );
		$payment_items = [ array_filter( [ 'unitAmount' => (int) round( $inst->amount ), 'quantity' => 1, 'code' => $product_code ?: null ] ) ];

		$expiry_hours = (int) ( $settings['invoice_expiry_hours'] ?? 24 );
		$expiry_at    = ( new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) ) )
		                    ->modify( "+{$expiry_hours} hours" )
		                    ->format( DateTime::ATOM );

		$product_name = get_the_title( $sub->product_id ) ?: __( 'course subscription', 'wc-irembopay' );
		$invoice_data = [
			'transactionId'            => sprintf( 'WC-INST-%d-%d-%s', $sub->id, $inst->installment_no, wp_generate_password( 6, false ) ),
			'paymentAccountIdentifier' => $payment_identifier,
			'customer'                 => [
				'email'       => $user->user_email,
				'phoneNumber' => get_user_meta( $user->ID, 'billing_phone', true ) ?: '',
				'name'        => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
			],
			'paymentItems' => $payment_items,
			'description'  => sprintf(
				__( 'Installment %d of %d – %s', 'wc-irembopay' ),
				$inst->installment_no, $inst->total_installments, $product_name
			),
			'language'  => 'EN',
			'expiryAt'  => $expiry_at,
		];

		$api      = new IremboPay_API( $secret_key );
		$response = $api->create_invoice( $invoice_data );

		if ( empty( $response['success'] ) || empty( $response['data']['invoiceNumber'] ) ) {
			$error = $response['message'] ?? 'Unknown error';
			IremboPay_Logger::error( "Installment #{$inst->id} invoice failed: {$error}" );
			IremboPay_Installment_DB::update( (int) $inst->id, [ 'status' => 'failed' ] );
			return;
		}

		$invoice_number = $response['data']['invoiceNumber'];
		IremboPay_Installment_DB::update( (int) $inst->id, [
			'invoice_number' => $invoice_number,
			'status'         => 'overdue',
		] );

		self::send_email_reminder( $user, $sub, $inst, $invoice_number );
		IremboPay_Logger::info( "Installment #{$inst->id} invoice {$invoice_number} created (now overdue)." );
	}

	// ------------------------------------------------------------------ //
	//  Internal: suspend access after grace period
	// ------------------------------------------------------------------ //

	private static function suspend_access( object $sub, object $inst, \WP_User $user ): void {
		$key = "irembopay_inst_suspended_{$sub->id}";
		if ( get_option( $key ) ) { return; } // already suspended

		update_option( $key, current_time( 'mysql' ), false );
		do_action( 'irembopay_installment_access_suspended', (int) $sub->id );
		self::send_email_access_revoked( $user, $sub, $inst );
		IremboPay_Logger::info( "Installment #{$inst->id}: access suspended for sub #{$sub->id}." );
	}

	// ------------------------------------------------------------------ //
	//  Helper
	// ------------------------------------------------------------------ //

	private static function billing_cycle_days( string $period, int $interval ): int {
		$map = [ 'day' => 1, 'week' => 7, 'month' => 30, 'year' => 365 ];
		return ( $map[ $period ] ?? 30 ) * $interval;
	}

	// ------------------------------------------------------------------ //
	//  Emails
	// ------------------------------------------------------------------ //

	public static function send_email_reminder( \WP_User $user, object $sub, object $inst, string $invoice_number = '' ): void {
		$site_name     = get_bloginfo( 'name' );
		$customer_name = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
		$product_name  = get_the_title( $sub->product_id ) ?: __( 'course subscription', 'wc-irembopay' );
		$amount        = wc_price( $inst->amount, [ 'currency' => $inst->currency ] );
		$due           = date_i18n( get_option( 'date_format' ), strtotime( $inst->due_date ) );
		$pay_url       = '';

		if ( $invoice_number ) {
			$order = wc_get_order( (int) $inst->order_id );
			if ( $order ) {
				$pay_url = add_query_arg( [
					'irembopay_payment' => '1',
					'order_id'          => $order->get_id(),
					'invoice_number'    => rawurlencode( $invoice_number ),
					'key'               => $order->get_order_key(),
				], home_url( '/' ) );
			}
		}

		$subject = sprintf( __( '[%s] Installment %d of %d due – action required', 'wc-irembopay' ), $site_name, $inst->installment_no, $inst->total_installments );
		$message = self::email_html( $site_name, $customer_name, [
			'heading' => sprintf( __( 'Installment %d of %d Due', 'wc-irembopay' ), $inst->installment_no, $inst->total_installments ),
			'body'    => sprintf( __( 'Your installment payment for %s is due on %s.', 'wc-irembopay' ), '<strong>' . esc_html( $product_name ) . '</strong>', '<strong>' . esc_html( $due ) . '</strong>' ),
			'amount'  => $amount,
			'pay_url' => $pay_url,
			'warn'    => '',
		] );

		wp_mail( $user->user_email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
	}

	private static function send_email_overdue_notice( \WP_User $user, object $sub, object $inst, int $grace_days_left ): void {
		$site_name     = get_bloginfo( 'name' );
		$customer_name = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
		$product_name  = get_the_title( $sub->product_id ) ?: __( 'course subscription', 'wc-irembopay' );
		$amount        = wc_price( $inst->amount, [ 'currency' => $inst->currency ] );

		$pay_url = '';
		if ( $inst->invoice_number ) {
			$order = wc_get_order( (int) $inst->order_id );
			if ( $order ) {
				$pay_url = add_query_arg( [
					'irembopay_payment' => '1',
					'order_id'          => $order->get_id(),
					'invoice_number'    => rawurlencode( $inst->invoice_number ),
					'key'               => $order->get_order_key(),
				], home_url( '/' ) );
			}
		}

		$subject = sprintf( __( '[%s] Overdue installment – %d day(s) to pay before access is suspended', 'wc-irembopay' ), $site_name, $grace_days_left );
		$message = self::email_html( $site_name, $customer_name, [
			'heading' => __( 'Overdue Installment Payment', 'wc-irembopay' ),
			'body'    => sprintf( __( 'Your installment %d of %d for %s is overdue.', 'wc-irembopay' ), $inst->installment_no, $inst->total_installments, '<strong>' . esc_html( $product_name ) . '</strong>' ),
			'amount'  => $amount,
			'pay_url' => $pay_url,
			'warn'    => sprintf( __( '⚠️ You have %d day(s) remaining to pay before your course access is suspended.', 'wc-irembopay' ), $grace_days_left ),
		] );

		wp_mail( $user->user_email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
	}

	private static function send_email_access_revoked( \WP_User $user, object $sub, object $inst ): void {
		$site_name     = get_bloginfo( 'name' );
		$customer_name = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
		$product_name  = get_the_title( $sub->product_id ) ?: __( 'course subscription', 'wc-irembopay' );
		$amount        = wc_price( $inst->amount, [ 'currency' => $inst->currency ] );

		$pay_url = '';
		if ( $inst->invoice_number ) {
			$order = wc_get_order( (int) $inst->order_id );
			if ( $order ) {
				$pay_url = add_query_arg( [
					'irembopay_payment' => '1',
					'order_id'          => $order->get_id(),
					'invoice_number'    => rawurlencode( $inst->invoice_number ),
					'key'               => $order->get_order_key(),
				], home_url( '/' ) );
			}
		}

		$subject = sprintf( __( '[%s] Course access suspended – overdue installment payment', 'wc-irembopay' ), $site_name );
		$message = self::email_html( $site_name, $customer_name, [
			'heading' => __( 'Course Access Suspended', 'wc-irembopay' ),
			'body'    => sprintf( __( 'Your access to %s has been suspended because installment %d of %d was not paid within the grace period.', 'wc-irembopay' ), '<strong>' . esc_html( $product_name ) . '</strong>', $inst->installment_no, $inst->total_installments ),
			'amount'  => $amount,
			'pay_url' => $pay_url,
			'warn'    => __( '⚠️ Pay now to restore your access immediately. Your end date will be extended by the number of days suspended.', 'wc-irembopay' ),
		] );

		wp_mail( $user->user_email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
	}

	private static function send_email_access_restored( \WP_User $user, object $sub, object $inst ): void {
		$site_name     = get_bloginfo( 'name' );
		$customer_name = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
		$product_name  = get_the_title( $sub->product_id ) ?: __( 'course subscription', 'wc-irembopay' );
		$new_end       = ! empty( $sub->end_date ) ? date_i18n( get_option( 'date_format' ), strtotime( $sub->end_date ) ) : '';

		$subject = sprintf( __( '[%s] Course access restored – payment received', 'wc-irembopay' ), $site_name );
		$message = self::email_html( $site_name, $customer_name, [
			'heading' => __( 'Course Access Restored', 'wc-irembopay' ),
			'body'    => sprintf(
				__( 'Your payment for installment %d of %d has been received and your access to %s has been restored.%s', 'wc-irembopay' ),
				$inst->installment_no, $inst->total_installments,
				'<strong>' . esc_html( $product_name ) . '</strong>',
				$new_end ? ' ' . sprintf( __( 'Your new access end date is %s.', 'wc-irembopay' ), '<strong>' . esc_html( $new_end ) . '</strong>' ) : ''
			),
			'amount'  => '',
			'pay_url' => '',
			'warn'    => '',
		] );

		wp_mail( $user->user_email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
	}

	private static function email_html( string $site_name, string $customer_name, array $d ): string {
		ob_start(); ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px}
.w{max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.h{background:#2563eb;color:#fff;padding:28px 32px}.h h1{margin:0;font-size:22px}
.b{padding:32px;color:#333;line-height:1.7}
.box{background:#f0f7ff;border-left:4px solid #2563eb;padding:16px 20px;border-radius:4px;margin:20px 0}
.box strong{font-size:1.35em;color:#1d4ed8}
.btn{display:inline-block;background:#2563eb;color:#fff!important;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:bold;font-size:16px;margin:20px 0}
.warn{background:#fff7ed;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:4px;font-size:.9em;color:#92400e;margin-top:16px}
.foot{background:#f9f9f9;padding:14px 32px;font-size:12px;color:#aaa;text-align:center}
</style></head><body>
<div class="w">
<div class="h"><h1><?php echo esc_html( $site_name ); ?></h1></div>
<div class="b">
<p><?php printf( esc_html__( 'Hello %s,', 'wc-irembopay' ), esc_html( $customer_name ) ); ?></p>
<h2 style="color:#1e40af"><?php echo esc_html( $d['heading'] ); ?></h2>
<p><?php echo wp_kses_post( $d['body'] ); ?></p>
<?php if ( $d['amount'] ) : ?><div class="box"><?php esc_html_e( 'Amount due:', 'wc-irembopay' ); ?><br><strong><?php echo $d['amount']; ?></strong></div><?php endif; ?>
<?php if ( $d['pay_url'] ) : ?><a href="<?php echo esc_url( $d['pay_url'] ); ?>" class="btn"><?php esc_html_e( 'Pay Now', 'wc-irembopay' ); ?></a><?php endif; ?>
<?php if ( $d['warn'] ) : ?><div class="warn"><?php echo wp_kses_post( $d['warn'] ); ?></div><?php endif; ?>
</div>
<div class="foot">&copy; <?php echo date( 'Y' ); ?> <?php echo esc_html( $site_name ); ?></div>
</div></body></html>
<?php return ob_get_clean();
	}
}
